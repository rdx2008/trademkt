<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Afastamento;
use App\Models\Agencia;
use App\Models\ContratoVisita;
use App\Models\Industria;
use App\Models\Ocorrencia;
use App\Models\Pdv;
use App\Models\Roteiro;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visita;
use App\Services\Roteiros\Atribuicao;
use App\Services\Roteiros\GeradorVisitas;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Ficha do promotor: agenda e histórico vindos da indústria, afastamentos, ocorrências e aparelhos. */
class FichaPromotorTest extends TestCase
{
    private const URL = 'http://sul.test';

    private Tenant $industria;

    private Tenant $agencia;

    private User $admin;

    private User $supervisor;

    private User $joao;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00'); // quarta
        $this->industria = $this->criarCliente('gaboardi');
        $this->agencia = $this->criarCliente('sul', 'agencia');
        $local = $this->vincular($this->industria, $this->agencia);
        $propria = $this->agencia->run(fn () => Agencia::first()->id);

        $this->admin = $this->agencia->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
        $this->supervisor = $this->criarUsuario($this->agencia, Perfil::Supervisor, ['email' => 'sup@sul.test', 'agencia_id' => $propria]);
        $this->joao = $this->criarUsuario($this->agencia, Perfil::Promotor, [
            'nome' => 'João da Silva', 'email' => 'joao@sul.test', 'agencia_id' => $propria, 'tipo_vinculo' => 'clt',
        ]);

        $this->industria->run(function () use ($local) {
            $marca = Industria::first();
            $pdv = Pdv::create(['cnpj' => self::cnpj(1), 'nome' => 'Super Centro', 'cidade' => 'Caxias do Sul', 'uf' => 'RS']);
            ContratoVisita::create(['pdv_id' => $pdv->id, 'industria_id' => $marca->id, 'agencia_id' => $local->id, 'frequencia_semana' => 1, 'dias' => [3], 'inicio' => '2026-10-01']);
            app(GeradorVisitas::class)->gerar(Carbon::parse('2026-10-05'), Carbon::parse('2026-10-25'));
            // Visita passada (7/10), que o gerador não cria retroativamente.
            $passada = Visita::create(['data' => '2026-10-07', 'data_prevista' => '2026-10-07', 'pdv_id' => $pdv->id, 'industria_id' => $marca->id, 'agencia_id' => $local->id, 'contrato_id' => ContratoVisita::first()->id, 'origem' => 'contrato']);
            $passada->update(['roteiro_id' => Roteiro::create(['data' => '2026-10-07', 'promotor_id' => $this->joao->id, 'agencia_id' => $local->id])->id]);
            foreach (Visita::whereDate('data', '>=', '2026-10-14')->orderBy('data')->get() as $visita) {
                app(Atribuicao::class)->mover($visita, $this->joao->id, $visita->data, null);
            }
            // A visita de 7/10 foi um impedimento de acesso, com 20 minutos.
            Visita::whereDate('data', '2026-10-07')->update(['status' => 'em_atencao', 'tipo_conclusao' => 'impedimento', 'duracao_segundos' => 1200]);
            Visita::whereDate('data', '2026-10-14')->update(['status' => 'realizada', 'tipo_conclusao' => 'normal', 'duracao_segundos' => 600]);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_ficha_soma_agenda_e_historico_das_industrias_atendidas(): void
    {
        $this->actingAs($this->admin)->get(self::URL."/promotores/{$this->joao->id}")
            ->assertOk()
            ->assertSee('João da Silva')
            ->assertSee('Super Centro')
            ->assertSee('Gaboardi')
            ->assertViewHas('operacional', function ($op) {
                return $op['metricas']['previstas'] === 1
                    && $op['metricas']['realizadas'] === 0
                    && $op['metricas']['impedimentos'] === 1
                    && $op['metricas']['tempo_medio_min'] === 20
                    && $op['agenda']->keys()->all() === ['2026-10-14'];
            });
    }

    public function test_ficha_nunca_mostra_o_cpf_inteiro(): void
    {
        $this->agencia->run(fn () => $this->joao->forceFill(['cpf' => '529.982.247-25'])->save());

        $this->actingAs($this->admin)->get(self::URL."/promotores/{$this->joao->id}")
            ->assertOk()->assertDontSee('529.982.247-25')->assertSee('***.982.247-**');
    }

    public function test_afastamento_e_ocorrencia_so_o_admin_registra_e_aparecem_na_lista(): void
    {
        $this->actingAs($this->admin);
        $this->post(self::URL."/promotores/{$this->joao->id}/afastamentos", [
            'tipo' => 'ferias', 'inicio' => '2026-10-10', 'fim' => '2026-10-20', 'observacao' => 'Férias de outubro',
        ])->assertRedirect(self::URL."/promotores/{$this->joao->id}");
        $this->post(self::URL."/promotores/{$this->joao->id}/ocorrencias", [
            'tipo' => 'elogio', 'data' => '2026-10-13', 'descricao' => 'Cliente elogiou a gôndola',
        ])->assertRedirect();

        $this->get(self::URL."/promotores/{$this->joao->id}")->assertOk()
            ->assertSee('Férias de outubro')->assertSee('Cliente elogiou a gôndola')->assertSee('Afastado hoje');
        $this->get(self::URL.'/promotores')->assertOk()->assertSee('Afastado (Férias)');

        $this->agencia->run(function () {
            $this->assertSame(1, Afastamento::count());
            $this->assertSame(1, Ocorrencia::count());
            $this->assertNotNull($this->joao->fresh()->afastamentoEm('2026-10-15'));
            $this->assertNull($this->joao->fresh()->afastamentoEm('2026-10-21'));
            $this->assertSame(['afastamento_registrado', 'ocorrencia_registrada'], $this->joao->auditoria()->pluck('acao')->sort()->values()->all());
        });

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->supervisor);
        $this->get(self::URL."/promotores/{$this->joao->id}")->assertOk()->assertSee('Cliente elogiou a gôndola')->assertDontSee('Registrar afastamento');
        $this->post(self::URL."/promotores/{$this->joao->id}/afastamentos", ['tipo' => 'folga', 'inicio' => '2026-10-10', 'fim' => '2026-10-11'])->assertForbidden();
        $this->post(self::URL."/promotores/{$this->joao->id}/ocorrencias", ['tipo' => 'falta', 'data' => '2026-10-10', 'descricao' => 'x'])->assertForbidden();
    }

    public function test_validacoes_de_afastamento_e_ocorrencia(): void
    {
        $this->actingAs($this->admin);
        $this->post(self::URL."/promotores/{$this->joao->id}/afastamentos", ['tipo' => 'ferias', 'inicio' => '2026-10-20', 'fim' => '2026-10-10'])
            ->assertSessionHasErrors('fim');
        $this->post(self::URL."/promotores/{$this->joao->id}/ocorrencias", ['tipo' => 'elogio', 'data' => '2026-12-01', 'descricao' => 'Futuro'])
            ->assertSessionHasErrors('data');
        $this->post(self::URL."/promotores/{$this->joao->id}/ocorrencias", ['tipo' => 'inventado', 'data' => '2026-10-01', 'descricao' => 'x'])
            ->assertSessionHasErrors('tipo');
    }

    public function test_remover_afastamento(): void
    {
        $afastamento = $this->agencia->run(fn () => $this->joao->afastamentos()->create(['tipo' => 'folga', 'inicio' => '2026-10-14', 'fim' => '2026-10-14']));
        $this->actingAs($this->admin)->delete(self::URL."/promotores/{$this->joao->id}/afastamentos/{$afastamento->id}")->assertRedirect();
        $this->agencia->run(fn () => $this->assertSame(0, Afastamento::count()));
    }

    public function test_revogar_aparelho_desconecta_so_aquele_token(): void
    {
        [$a, $b] = $this->agencia->run(function () {
            $a = $this->joao->createToken('Pixel 7', ['promotor'])->accessToken;
            $b = $this->joao->createToken('Moto G', ['promotor'])->accessToken;

            return [$a, $b];
        });

        $this->actingAs($this->admin)->get(self::URL."/promotores/{$this->joao->id}")->assertOk()->assertSee('Pixel 7')->assertSee('Moto G');
        $this->delete(self::URL."/promotores/{$this->joao->id}/aparelhos/{$a->id}")->assertRedirect();

        $this->agencia->run(fn () => $this->assertSame(['Moto G'], $this->joao->tokens()->pluck('name')->all()));
    }

    public function test_so_gestao_da_agencia_abre_a_ficha(): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->joao)->get(self::URL."/promotores/{$this->joao->id}")->assertForbidden();

        $this->app['auth']->forgetGuards();
        $adminIndustria = $this->industria->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
        $this->actingAs($adminIndustria)->get('http://gaboardi.test/promotores/1')->assertStatus(403);
    }
}
