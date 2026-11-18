<?php

namespace Tests\Feature;

use App\Enums\ModoEmpresa;
use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Models\Agencia;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vinculo;
use Tests\TestCase;

/** Fase 9, item 9.3: a indústria cria a agência interna (e agência externa nova) pelo painel. */
class AgenciaInternaTest extends TestCase
{
    private const URL = 'http://gaboardi.test';

    private Tenant $industria;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->industria = $this->criarCliente('gaboardi');
        $this->admin = $this->industria->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
        tenancy()->end();
    }

    private function dados(array $extra = []): array
    {
        return array_merge([
            'tipo_agencia' => 'interna',
            'nome' => 'Gaboardi Promoções',
            'cnpj' => '11.222.333/0001-81',
            'subdominio' => 'gaboardipromo',
            'admin_nome' => 'Paula Coordenadora',
            'admin_email' => 'paula@gaboardipromo.com.br',
        ], $extra);
    }

    public function test_admin_da_industria_cria_agencia_interna_ja_vinculada_com_link_de_acesso(): void
    {
        $this->actingAs($this->admin)->get(self::URL.'/agencias')->assertOk()->assertSee('Nova agência');
        $this->get(self::URL.'/agencias/nova')->assertOk()->assertSee('Agência interna');

        $this->post(self::URL.'/agencias', $this->dados())
            ->assertRedirect(self::URL.'/agencias')
            ->assertSessionHas('convite_agencia');
        $convite = session('convite_agencia')['url'];

        $agencia = Tenant::where('tipo', TipoInstalacao::Agencia->value)->sole();
        $this->assertSame(ModoEmpresa::Interna, $agencia->modo);
        $this->assertSame('gaboardi', $agencia->dono_tenant_id);
        $this->assertSame('autocadastro', $agencia->origem);

        $vinculo = Vinculo::sole();
        $this->assertSame([$this->industria->id, $agencia->id], [$vinculo->industria_tenant_id, $vinculo->agencia_tenant_id]);
        $this->assertSame(Vinculo::ATIVO, $vinculo->status);
        $this->assertSame('industria', $vinculo->origem);
        $this->assertTrue($this->industria->run(fn () => Agencia::where('agencia_tenant_id', $agencia->id)->where('ativo', true)->exists()));

        $this->get(self::URL.'/agencias')->assertOk()->assertSee('Gaboardi Promoções')->assertSee('Interna')->assertSee($convite, false);

        // O responsável cria a senha e vira admin da instalação da agência.
        $this->app['auth']->forgetGuards();
        $this->post($convite, ['password' => 'SenhaForte123', 'password_confirmation' => 'SenhaForte123', 'aceite' => 1])->assertRedirect();
        $admin = $agencia->run(fn () => User::where('email', 'paula@gaboardipromo.com.br')->sole());
        $this->assertSame(Perfil::AdminInstalacao, $admin->perfil);
    }

    public function test_agencia_externa_nova_nasce_independente(): void
    {
        $this->actingAs($this->admin)->post(self::URL.'/agencias', $this->dados(['tipo_agencia' => 'externa', 'nome' => 'Promo Sul']))->assertRedirect();

        $agencia = Tenant::where('tipo', TipoInstalacao::Agencia->value)->sole();
        $this->assertSame(ModoEmpresa::Propria, $agencia->modo);
        $this->assertNull($agencia->dono_tenant_id);
        $this->get(self::URL.'/agencias')->assertSee('Externa');
    }

    public function test_so_o_admin_da_industria_cria(): void
    {
        $gerente = $this->criarUsuario($this->industria, Perfil::GerenteTrade, ['industria_id' => 1]);
        $this->actingAs($gerente)->get(self::URL.'/agencias/nova')->assertForbidden();
        $this->post(self::URL.'/agencias', $this->dados())->assertForbidden();
        $this->get(self::URL.'/agencias')->assertDontSee('Nova agência');

        $agencia = $this->criarCliente('agx', 'agencia');
        tenancy()->end();
        $adminAgencia = $agencia->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
        $this->app['auth']->forgetGuards();
        $this->actingAs($adminAgencia)->post('http://agx.test/agencias', $this->dados())->assertForbidden();
        $this->assertSame(0, Vinculo::count());
    }

    public function test_dados_invalidos_nao_criam_nada(): void
    {
        $this->actingAs($this->admin);
        $this->post(self::URL.'/agencias', $this->dados(['cnpj' => '123']))->assertSessionHasErrors('cnpj');
        $this->post(self::URL.'/agencias', $this->dados(['tipo_agencia' => 'outra']))->assertSessionHasErrors('tipo_agencia');
        $this->post(self::URL.'/agencias', $this->dados(['admin_email' => 'x']))->assertSessionHasErrors('admin_email');
        $this->assertSame(1, Tenant::count());
    }
}
