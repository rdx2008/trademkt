<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Admin;
use App\Models\Agencia;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vinculo;
use App\Services\Rede\Vinculos;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Vínculo indústria–agência feito pelo super admin: a agência passa a existir no banco da
 * indústria (linha de `agencias` com `agencia_tenant_id`), sem solicitação nem aceite.
 */
class VinculoTest extends TestCase
{
    private const CENTRAL = 'http://central.test';

    private Tenant $industria;

    private Tenant $agencia;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->industria = $this->criarCliente('gaboardi');
        $this->agencia = $this->criarCliente('sul', 'agencia');
        $this->admin = Admin::create(['nome' => 'Rodolfo', 'email' => 'r@mastervps.test', 'password' => 'senha1234']);
    }

    private function adminDaIndustria(): User
    {
        return $this->industria->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
    }

    public function test_super_admin_vincula_agencia_a_industria_com_regioes(): void
    {
        $this->get(self::CENTRAL.'/vinculos')->assertRedirect(self::CENTRAL.'/entrar');

        $this->actingAs($this->admin, 'admin')->get(self::CENTRAL.'/vinculos')->assertOk()
            ->assertSee('Nenhum vínculo ainda')->assertSee('Gaboardi')->assertSee('Sul');

        $this->post(self::CENTRAL.'/vinculos', [
            'industria_tenant_id' => 'gaboardi',
            'agencia_tenant_id' => 'sul',
            'regioes' => [['uf' => 'RS', 'cidade' => 'Caxias do Sul'], ['uf' => 'SC', 'cidade' => '']],
        ])->assertSessionHasNoErrors()->assertRedirect(self::CENTRAL.'/vinculos');

        $vinculo = Vinculo::sole();
        $this->assertSame('gaboardi', $vinculo->industria_tenant_id);
        $this->assertSame('sul', $vinculo->agencia_tenant_id);
        $this->assertTrue($vinculo->ativo);
        $this->assertSame([['uf' => 'RS', 'cidade' => 'Caxias do Sul'], ['uf' => 'SC', 'cidade' => null]], $vinculo->regioes);
        $this->get(self::CENTRAL.'/vinculos')->assertOk()->assertSee('Sul agora atende Gaboardi')->assertSee('Caxias do Sul, RS; SC');

        // A agência aparece no banco da indústria, pronta para contratos, sem nenhum usuário local.
        $this->industria->run(function () {
            $local = Agencia::where('agencia_tenant_id', 'sul')->sole();
            $this->assertSame('Sul', $local->nome);
            $this->assertTrue($local->ativo);
            $this->assertSame(0, User::where('agencia_id', $local->id)->count());
        });
        // O banco da agência não ganha nada da indústria.
        $this->agencia->run(fn () => $this->assertSame(1, Agencia::count()));
    }

    public function test_valida_tipos_duplicidade_e_regioes(): void
    {
        $this->actingAs($this->admin, 'admin');
        $this->post(self::CENTRAL.'/vinculos', ['industria_tenant_id' => 'sul', 'agencia_tenant_id' => 'gaboardi'])
            ->assertSessionHasErrors(['industria_tenant_id', 'agencia_tenant_id']);
        $this->post(self::CENTRAL.'/vinculos', ['industria_tenant_id' => 'gaboardi', 'agencia_tenant_id' => 'sul', 'regioes' => [['uf' => 'XX']]])
            ->assertSessionHasErrors('regioes.0.uf');
        $this->post(self::CENTRAL.'/vinculos', ['industria_tenant_id' => 'gaboardi', 'agencia_tenant_id' => 'sul', 'regioes' => [['uf' => 'RS', 'cidade' => 'Caxias'], ['uf' => 'RS', 'cidade' => 'caxias']]])
            ->assertSessionHasErrors('regioes');
        $this->assertSame(0, Vinculo::count());

        // Sem regiões: vale para todas.
        $this->post(self::CENTRAL.'/vinculos', ['industria_tenant_id' => 'gaboardi', 'agencia_tenant_id' => 'sul', 'regioes' => [['uf' => '', 'cidade' => '']]])
            ->assertSessionHasNoErrors();
        $this->assertNull(Vinculo::sole()->regioes);
        $this->assertSame('Todas as regiões', Vinculo::sole()->regioesRotulo());

        $this->post(self::CENTRAL.'/vinculos', ['industria_tenant_id' => 'gaboardi', 'agencia_tenant_id' => 'sul'])
            ->assertSessionHasErrors('agencia_tenant_id');
        $this->assertSame(1, Vinculo::count());

        $this->agencia->update(['ativo' => false]);
        $this->expectException(ValidationException::class);
        app(Vinculos::class)->criar($this->criarCliente('outra'), $this->agencia->fresh());
    }

    public function test_desativar_o_vinculo_desativa_a_agencia_na_industria_e_reativar_a_traz_de_volta(): void
    {
        $vinculo = app(Vinculos::class)->criar($this->industria, $this->agencia, [['uf' => 'RS']]);

        $this->actingAs($this->admin, 'admin')->get(self::CENTRAL."/vinculos/{$vinculo->id}/editar")->assertOk()->assertSee('Sul atende Gaboardi');
        $this->put(self::CENTRAL."/vinculos/{$vinculo->id}", ['regioes' => [['uf' => 'PR']]])
            ->assertSessionHasNoErrors()->assertRedirect(self::CENTRAL.'/vinculos');

        $vinculo->refresh();
        $this->assertFalse($vinculo->ativo);
        $this->assertSame([['uf' => 'PR', 'cidade' => null]], $vinculo->regioes);
        $this->industria->run(fn () => $this->assertFalse(Agencia::where('agencia_tenant_id', 'sul')->sole()->ativo));
        $this->assertSame(0, Vinculo::de($this->industria)->count());

        $this->agencia->update(['nome' => 'Sul Promoções']);
        $this->put(self::CENTRAL."/vinculos/{$vinculo->id}", ['ativo' => '1'])->assertSessionHasNoErrors();
        $this->industria->run(function () {
            $local = Agencia::where('agencia_tenant_id', 'sul')->sole();
            $this->assertTrue($local->ativo);
            $this->assertSame('Sul Promoções', $local->nome, 'o nome acompanha o cadastro central');
        });
        $this->assertSame(1, Vinculo::de($this->industria)->count());
        $this->assertSame(1, Vinculo::de($this->agencia)->count());
    }

    public function test_industria_consulta_as_agencias_vinculadas_sem_cadastrar_nem_editar(): void
    {
        $admin = $this->adminDaIndustria();
        $this->actingAs($admin)->get('http://gaboardi.test/painel')->assertOk()->assertSee('Nenhuma agência vinculada ainda');
        $this->get('http://gaboardi.test/agencias')->assertOk()->assertSee('Nenhuma agência disponível para consulta.');

        app(Vinculos::class)->criar($this->industria, $this->agencia, [['uf' => 'RS', 'cidade' => 'Caxias do Sul']]);
        tenancy()->end();

        $this->actingAs($admin)->get('http://gaboardi.test/painel')->assertOk()->assertDontSee('Nenhuma agência vinculada ainda');
        $this->get('http://gaboardi.test/agencias')->assertOk()
            ->assertSee('Sul')->assertSee('Caxias do Sul, RS')
            ->assertDontSee('Nova agência')->assertDontSee('Agências internas')->assertDontSee('>Editar</a>', false);
        $this->get('http://gaboardi.test/agencias/create')->assertNotFound();
        $this->post('http://gaboardi.test/agencias', ['nome' => 'Interna'])->assertMethodNotAllowed();
        $this->get('http://gaboardi.test/painel')->assertDontSee('Buscar agências')->assertDontSee('parcerias');
    }
}
