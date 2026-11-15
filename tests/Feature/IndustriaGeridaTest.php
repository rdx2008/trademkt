<?php

namespace Tests\Feature;

use App\Enums\ModoEmpresa;
use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Models\Agencia;
use App\Models\ContratoVisita;
use App\Models\Industria;
use App\Models\Pdv;
use App\Models\Regiao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vinculo;
use App\Models\Visita;
use App\Services\ProvisionarCliente;
use App\Services\Roteiros\GeradorVisitas;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Fase 9, itens 9.5 e 9.6: indústria gerida pela agência e entrega do cadastro à indústria. */
class IndustriaGeridaTest extends TestCase
{
    private const SUL = 'http://sul.test';

    private Tenant $agencia;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencia = $this->criarCliente('sul', 'agencia');
        tenancy()->end();
        $this->admin = $this->agencia->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarGerida(string $relacao = 'cliente'): Tenant
    {
        $this->actingAs($this->admin)->post(self::SUL.'/empresas-vinculadas/industrias', [
            'nome' => 'Doces Serra', 'cnpj' => '11.222.333/0001-81', 'relacao' => $relacao,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Tenant::where('tipo', TipoInstalacao::Industria->value)->sole();
    }

    public function test_agencia_cadastra_industria_gerida_e_administra_o_cadastro_dela(): void
    {
        $industria = $this->criarGerida();
        $this->assertSame(ModoEmpresa::Gerida, $industria->modo);
        $this->assertSame('sul', $industria->dono_tenant_id);
        $vinculo = Vinculo::sole();
        $this->assertSame([Vinculo::ATIVO, 'agencia', 'cliente'], [$vinculo->status, $vinculo->origem, $vinculo->relacao]);

        $this->post(self::SUL."/operacao/{$industria->id}")->assertRedirect();
        $this->get(self::SUL.'/pdvs/create')->assertOk();
        $this->get(self::SUL.'/skus/create')->assertOk();
        $this->get(self::SUL.'/skus/importar')->assertOk();
        $this->get(self::SUL.'/regioes/novo')->assertOk();
        $this->post(self::SUL.'/regioes', ['nome' => 'Serra Gaúcha'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($industria->run(fn () => Regiao::where('nome', 'Serra Gaúcha')->exists()), 'gravado no banco da indústria');
        $this->assertFalse($this->agencia->run(fn () => Regiao::where('nome', 'Serra Gaúcha')->exists()));
    }

    public function test_prospect_nao_gera_visita_ate_virar_cliente(): void
    {
        Carbon::setTestNow('2026-10-14 10:00:00');
        $industria = $this->criarGerida('prospect');
        $industria->run(function () {
            $pdv = Pdv::create(['cnpj' => self::cnpj(7), 'nome' => 'Mercado Serra']);
            ContratoVisita::create(['pdv_id' => $pdv->id, 'industria_id' => Industria::first()->id, 'agencia_id' => Agencia::first()->id,
                'frequencia_semana' => 1, 'dias' => [3], 'inicio' => '2026-10-01']);
            app(GeradorVisitas::class)->gerar(Carbon::parse('2026-10-12'), Carbon::parse('2026-10-18'));
            $this->assertSame(0, Visita::count(), 'prospect não executa visitas');
        });

        $vinculo = Vinculo::sole();
        $this->actingAs($this->admin)->patch(self::SUL."/empresas-vinculadas/{$vinculo->id}/relacao", ['relacao' => 'cliente'])->assertRedirect();
        $industria->run(function () {
            app(GeradorVisitas::class)->gerar(Carbon::parse('2026-10-12'), Carbon::parse('2026-10-18'));
            $this->assertSame(1, Visita::count());
        });
    }

    public function test_agencia_entrega_o_cadastro_e_a_industria_fica_independente(): void
    {
        $industria = $this->criarGerida();
        $industria->run(fn () => Regiao::create(['nome' => 'Feito pela agência']));
        $vinculo = Vinculo::sole();

        $this->post(self::SUL."/empresas-vinculadas/{$vinculo->id}/entregar", ['admin_nome' => 'Carla Doces', 'admin_email' => 'carla@docesserra.com.br'])
            ->assertRedirect()->assertSessionHas('convite_industria');
        $link = session('convite_industria')['url'];

        $industria->refresh();
        $this->assertSame(ModoEmpresa::Propria, $industria->modo);
        $this->assertNull($industria->dono_tenant_id);
        $this->assertSame('sul', $industria->assumida_de);
        $this->assertSame(Vinculo::ATIVO, $vinculo->fresh()->status, 'a agência continua vinculada');
        $this->assertTrue($industria->run(fn () => Regiao::where('nome', 'Feito pela agência')->exists()), 'dados preservados');

        // O responsável cria a senha e vira admin da indústria.
        $this->app['auth']->forgetGuards();
        $this->post($link, ['password' => 'SenhaForte123', 'password_confirmation' => 'SenhaForte123', 'aceite' => 1])->assertRedirect();
        $admin = $industria->run(fn () => User::where('email', 'carla@docesserra.com.br')->sole());
        $this->assertTrue($admin->ativo);
        $this->assertSame(Perfil::AdminInstalacao, $admin->perfil);

        // A agência deixa de administrar o cadastro (agora da indústria).
        $this->actingAs($this->admin)->post(self::SUL."/operacao/{$industria->id}");
        $this->get(self::SUL.'/pdvs/create')->assertForbidden();
        $this->get(self::SUL.'/roteiros')->assertOk();

        // Não dá para entregar de novo.
        $this->post(self::SUL."/empresas-vinculadas/{$vinculo->id}/entregar", ['admin_nome' => 'X', 'admin_email' => 'x@x.com'])->assertSessionHasErrors('industria');
    }

    public function test_outra_agencia_nao_entrega_nem_administra(): void
    {
        $industria = $this->criarGerida();
        $vinculo = Vinculo::sole();

        $outra = $this->criarCliente('norte', 'agencia');
        tenancy()->end();
        $adminNorte = $outra->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
        $this->app['auth']->forgetGuards();
        $this->actingAs($adminNorte)->post("http://norte.test/empresas-vinculadas/{$vinculo->id}/entregar", ['admin_nome' => 'X', 'admin_email' => 'x@x.com'])->assertNotFound();
        $this->assertSame(ModoEmpresa::Gerida, $industria->fresh()->modo);
    }

    public function test_super_admin_e_orientado_a_pedir_a_entrega_quando_o_cnpj_e_de_industria_gerida(): void
    {
        $this->criarGerida();
        try {
            app(ProvisionarCliente::class)->executar(['id' => 'docesserra', 'nome' => 'Doces Serra', 'tipo' => 'industria', 'cnpj' => '11222333000181',
                'dominios' => ['docesserra.test'], 'admin_nome' => 'A', 'admin_email' => 'a@docesserra.test']);
            $this->fail('deveria recusar');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('entregar o cadastro', $e->errors()['cnpj'][0]);
        }
    }
}
