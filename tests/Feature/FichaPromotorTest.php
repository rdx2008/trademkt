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
use App\Services\Operacao\Equipe;
use App\Services\Roteiros\Atribuicao;
use App\Services\Roteiros\GeradorVisitas;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
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

    private function outraIndustria(): Tenant
    {
        $outra = $this->criarCliente('norte');
        $this->vincular($outra, $this->agencia);

        return $outra;
    }

    public function test_habilitacao_por_industria_restringe_atribuicao_e_roteiro_do_app(): void
    {
        $outra = $this->outraIndustria();
        $this->actingAs($this->admin);

        $this->put(self::URL."/promotores/{$this->joao->id}/industrias", ['industrias' => ['inexistente']])->assertSessionHasErrors('industrias.0');
        $this->put(self::URL."/promotores/{$this->joao->id}/industrias", ['industrias' => [$outra->id]])->assertRedirect();
        $this->agencia->run(fn () => $this->assertSame([$outra->id], $this->joao->industriasHabilitadas()));

        $this->industria->run(function () {
            Equipe::esquecer();
            $local = Agencia::where('agencia_tenant_id', $this->agencia->id)->sole();
            $equipe = app(Equipe::class);
            $this->assertNull($equipe->promotorAtivo($local, $this->joao->id), 'habilitado só para a outra indústria');
            $this->assertCount(0, $equipe->promotores($local));
            $this->assertCount(1, $equipe->promotores($local, true, false), 'a leitura completa continua enxergando');
        });

        // O app deixa de receber o roteiro da indústria em que ele não está habilitado.
        Sanctum::actingAs($this->joao, ['promotor']);
        $this->getJson(self::URL.'/api/v1/roteiro?data=2026-10-14')->assertOk()->assertJsonCount(0, 'visitas');

        // Voltar para "todas" (nenhuma marcada).
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)->put(self::URL."/promotores/{$this->joao->id}/industrias", [])->assertRedirect();
        $this->agencia->run(fn () => $this->assertSame([], $this->joao->industriasHabilitadas()));
        $this->industria->run(function () {
            Equipe::esquecer();
            $local = Agencia::where('agencia_tenant_id', $this->agencia->id)->sole();
            $this->assertNotNull(app(Equipe::class)->promotorAtivo($local, $this->joao->id));
        });
    }

    public function test_afastamento_impede_atribuir_visita_no_periodo(): void
    {
        $this->agencia->run(fn () => $this->joao->afastamentos()->create(['tipo' => 'ferias', 'inicio' => '2026-10-20', 'fim' => '2026-10-22']));

        $this->industria->run(function () {
            Equipe::esquecer();
            $local = Agencia::where('agencia_tenant_id', $this->agencia->id)->sole();
            $equipe = app(Equipe::class);
            $this->assertNull($equipe->promotorAtivo($local, $this->joao->id, '2026-10-21'));
            $this->assertNotNull($equipe->promotorAtivo($local, $this->joao->id, '2026-10-23'));
            $this->assertNotNull($equipe->promotorAtivo($local, $this->joao->id), 'sem data, só ativo e habilitado');

            $visita = Visita::whereDate('data', '2026-10-21')->sole();
            $this->expectException(ValidationException::class);
            app(Atribuicao::class)->mover($visita, $this->joao->id, $visita->data, null);
        });
    }

    public function test_cidade_base_poe_o_promotor_da_cidade_primeiro(): void
    {
        $propria = $this->agencia->run(fn () => Agencia::first()->id);
        $this->agencia->run(function () {
            $this->joao->forceFill(['cidade_base' => 'Bento Gonçalves', 'uf_base' => 'RS'])->save();
        });
        $this->criarUsuario($this->agencia, Perfil::Promotor, ['nome' => 'Zélia Souza', 'email' => 'zelia@sul.test', 'agencia_id' => $propria, 'cidade_base' => 'Caxias do Sul', 'uf_base' => 'RS']);

        $this->industria->run(function () {
            Equipe::esquecer();
            $local = Agencia::where('agencia_tenant_id', $this->agencia->id)->sole();
            $equipe = app(Equipe::class);
            $this->assertSame(['João da Silva', 'Zélia Souza'], $equipe->promotores($local)->pluck('nome')->all(), 'sem cidade: por nome');
            $this->assertSame(['Zélia Souza', 'João da Silva'], $equipe->promotores($local, true, true, 'caxias do sul')->pluck('nome')->all());
        });
    }

    public function test_cracha_e_verificacao_publica_sem_dados_sensiveis(): void
    {
        $this->agencia->run(fn () => $this->joao->forceFill(['cpf' => '529.982.247-25', 'telefone' => '(54) 99999-0000'])->save());

        $pagina = $this->actingAs($this->admin)->get(self::URL."/promotores/{$this->joao->id}/cracha")->assertOk()->assertSee('João da Silva');
        $codigo = $this->agencia->run(fn () => $this->joao->fresh()->cracha_token);
        $this->assertSame(40, strlen($codigo));
        $pagina->assertSee(self::URL.'/verificar/'.$codigo, false);

        // Página pública: sem login, só primeiro nome e situação.
        $this->app['auth']->forgetGuards();
        $this->get(self::URL."/verificar/{$codigo}")->assertOk()
            ->assertSee('João')->assertSee('Cadastro ativo')
            ->assertDontSee('da Silva')->assertDontSee('joao@sul.test')->assertDontSee('529.982.247-25')->assertDontSee('99999-0000')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get(self::URL.'/verificar/'.str_repeat('a', 40))->assertNotFound();

        // Promotor desativado aparece como inativo.
        $this->agencia->run(fn () => $this->joao->forceFill(['ativo' => false])->save());
        $this->get(self::URL."/verificar/{$codigo}")->assertOk()->assertSee('Cadastro inativo')->assertDontSee('Cadastro ativo');
        $this->get(self::URL."/verificar/{$codigo}/foto")->assertNotFound();

        // O código é da instalação da agência: na indústria não existe.
        $this->get('http://gaboardi.test/verificar/'.$codigo)->assertNotFound();
    }

    public function test_cracha_so_para_gestao_da_agencia(): void
    {
        $this->actingAs($this->joao)->get(self::URL."/promotores/{$this->joao->id}/cracha")->assertForbidden();
    }

    public function test_app_recebe_o_endereco_do_cracha_so_para_promotor(): void
    {
        Sanctum::actingAs($this->joao, ['promotor']);
        $url = $this->getJson(self::URL.'/api/v1/me')->assertOk()->json('usuario.cracha_url');
        $codigo = $this->agencia->run(fn () => $this->joao->fresh()->cracha_token);
        $this->assertSame(self::URL.'/verificar/'.$codigo, $url);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->supervisor, ['*']);
        $this->getJson(self::URL.'/api/v1/me')->assertOk()->assertJsonPath('usuario.cracha_url', null);
    }
}
