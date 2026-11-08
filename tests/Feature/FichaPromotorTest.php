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
use App\Models\Sku;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visita;
use App\Models\VisitaSku;
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
        $this->agencia->run(fn () => $this->assertSame([$outra->id], $this->joao->fresh()->industriasHabilitadas()));

        $this->industria->run(function () {
            Equipe::esquecer();
            $local = Agencia::where('agencia_tenant_id', $this->agencia->id)->sole();
            $equipe = app(Equipe::class);
            $this->assertNull($equipe->promotorAtivo($local, $this->joao->id), 'habilitado só para a outra indústria');
            $this->assertCount(0, $equipe->promotores($local));
            $this->assertCount(1, $equipe->promotores($local, true, false), 'a leitura completa continua enxergando');
        });

        // O app deixa de receber o roteiro da indústria em que ele não está habilitado.
        Sanctum::actingAs($this->agencia->run(fn () => $this->joao->fresh()), ['promotor']);
        $this->getJson(self::URL.'/api/v1/roteiro?data=2026-10-14')->assertOk()->assertJsonCount(0, 'visitas');

        // Voltar para "todas" (nenhuma marcada).
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)->put(self::URL."/promotores/{$this->joao->id}/industrias", ['todas' => 1])->assertRedirect();
        $this->agencia->run(fn () => $this->assertNull($this->joao->fresh()->industriasHabilitadas()));
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

    private function colega(string $nome, string $email, ?string $cidade = null): User
    {
        $propria = $this->agencia->run(fn () => Agencia::first()->id);

        return $this->criarUsuario($this->agencia, Perfil::Promotor, ['nome' => $nome, 'email' => $email, 'agencia_id' => $propria, 'cidade_base' => $cidade, 'uf_base' => $cidade ? 'RS' : null]);
    }

    private function donoDaVisita21(): ?int
    {
        return $this->industria->run(fn () => Visita::whereDate('data', '2026-10-21')->sole()->fresh()->roteiro?->promotor_id);
    }

    public function test_afastar_com_visita_atribuida_avisa_e_redistribui_para_colega_da_cidade(): void
    {
        $this->colega('Bento Colega', 'bento@sul.test', 'Bento Gonçalves');
        $zelia = $this->colega('Zélia da Cidade', 'zelia@sul.test', 'Caxias do Sul');

        $this->actingAs($this->admin);
        $this->post(self::URL."/promotores/{$this->joao->id}/afastamentos", ['tipo' => 'ferias', 'inicio' => '2026-10-20', 'fim' => '2026-10-22'])
            ->assertRedirect()->assertSessionHas('redistribuir.n', 1);

        $this->get(self::URL."/promotores/{$this->joao->id}/redistribuir?inicio=2026-10-20&fim=2026-10-22")
            ->assertOk()->assertSee('Super Centro')->assertSee('Zélia da Cidade')->assertDontSee('Bento Colega');
        $this->assertSame($this->joao->id, $this->donoDaVisita21(), 'a prévia não move nada');

        $this->post(self::URL."/promotores/{$this->joao->id}/redistribuir", ['inicio' => '2026-10-20', 'fim' => '2026-10-22'])->assertRedirect();
        $this->assertSame($zelia->id, $this->donoDaVisita21());
        $this->agencia->run(fn () => $this->assertContains('visitas_redistribuidas', $this->joao->auditoria()->pluck('acao')->all()));
    }

    public function test_sem_substituto_a_visita_continua_com_o_promotor(): void
    {
        $this->actingAs($this->admin);
        $this->get(self::URL."/promotores/{$this->joao->id}/redistribuir?inicio=2026-10-20&fim=2026-10-22")->assertOk()->assertSee('Sem substituto');
        $this->post(self::URL."/promotores/{$this->joao->id}/redistribuir", ['inicio' => '2026-10-20', 'fim' => '2026-10-22'])
            ->assertRedirect()->assertSessionHas('status');
        $this->assertSame($this->joao->id, $this->donoDaVisita21());
    }

    public function test_colega_afastado_ou_sem_habilitacao_nao_recebe(): void
    {
        $maria = $this->colega('Maria Afastada', 'maria@sul.test');
        $this->agencia->run(fn () => $maria->afastamentos()->create(['tipo' => 'folga', 'inicio' => '2026-10-21', 'fim' => '2026-10-21']));
        $ana = $this->colega('Ana Restrita', 'ana@sul.test');
        $this->agencia->run(fn () => $ana->forceFill(['habilitacao_restrita' => true])->save());

        $this->actingAs($this->admin)->get(self::URL."/promotores/{$this->joao->id}/redistribuir?inicio=2026-10-21&fim=2026-10-21")
            ->assertOk()->assertSee('Sem substituto')->assertDontSee('Maria Afastada')->assertDontSee('Ana Restrita');
    }

    public function test_desativar_promotor_com_visitas_oferece_redistribuir(): void
    {
        $this->colega('Maria Colega', 'maria@sul.test');
        $this->actingAs($this->admin)->patch(self::URL."/promotores/{$this->joao->id}/ativo", ['ativo' => 0])
            ->assertRedirect()->assertSessionHas('redistribuir.n', 1);
    }

    public function test_so_gestao_redistribui(): void
    {
        $this->actingAs($this->joao)->get(self::URL."/promotores/{$this->joao->id}/redistribuir")->assertForbidden();
    }

    public function test_promotor_novo_comeca_sem_industria_habilitada(): void
    {
        $this->actingAs($this->admin)->post(self::URL.'/promotores', [
            'nome' => 'Novo Promotor', 'email' => 'novo@sul.test', 'cpf' => '529.982.247-25', 'tipo_vinculo' => 'clt',
        ])->assertRedirect();
        $novo = $this->agencia->run(fn () => User::where('email', 'novo@sul.test')->sole());

        $this->assertSame([], $this->agencia->run(fn () => $novo->industriasHabilitadas()));
        $this->get(self::URL."/promotores/{$novo->id}")->assertOk()->assertSee('Nenhuma indústria habilitada');

        $this->industria->run(function () use ($novo) {
            Equipe::esquecer();
            $local = Agencia::where('agencia_tenant_id', $this->agencia->id)->sole();
            $this->assertNull(app(Equipe::class)->promotorAtivo($local, $novo->id));
        });

        $this->put(self::URL."/promotores/{$novo->id}/industrias", ['industrias' => [$this->industria->id]])->assertRedirect();
        $this->industria->run(function () use ($novo) {
            Equipe::esquecer();
            $local = Agencia::where('agencia_tenant_id', $this->agencia->id)->sole();
            $this->assertNotNull(app(Equipe::class)->promotorAtivo($local, $novo->id));
        });
    }

    public function test_lista_mostra_visitas_de_hoje_aparelho_e_alerta_de_quem_nao_abriu_o_app(): void
    {
        $this->agencia->run(function () {
            $this->joao->createToken('Pixel 7', ['promotor']);
            $this->joao->forceFill(['aceite_privacidade_em' => now(), 'ultimo_acesso_em' => now()->subDays(2)])->save();
        });
        // Visita de hoje (14/10) ainda não iniciada: volta a "prevista".
        $this->industria->run(fn () => Visita::whereDate('data', '2026-10-14')->update(['status' => 'previsto', 'tipo_conclusao' => null]));

        $this->actingAs($this->admin)->get(self::URL.'/promotores')->assertOk()
            ->assertSee('1 visita(s) · 0 iniciada(s)')->assertSee('Ainda não abriu o app hoje')->assertSee('Pixel 7');

        // Abriu o app e iniciou a visita: o alerta some.
        $this->agencia->run(fn () => $this->joao->forceFill(['ultimo_acesso_em' => now()])->save());
        $this->industria->run(fn () => Visita::whereDate('data', '2026-10-14')->update(['status' => 'em_andamento']));
        $this->get(self::URL.'/promotores')->assertOk()->assertSee('1 visita(s) · 1 iniciada(s)')->assertDontSee('Ainda não abriu o app hoje');
    }

    public function test_lista_avisa_promotor_sem_industria_habilitada(): void
    {
        $novo = $this->colega('Sem Industria', 'sem@sul.test');
        $this->agencia->run(fn () => $novo->forceFill(['habilitacao_restrita' => true])->save());
        $this->actingAs($this->admin)->get(self::URL.'/promotores')->assertOk()->assertSee('Sem indústria habilitada');
    }

    public function test_relatorio_de_skus_por_dia_e_csv(): void
    {
        $this->industria->run(function () {
            $marca = Industria::first();
            $a = Sku::create(['industria_id' => $marca->id, 'codigo' => 'A1', 'descricao' => 'Detergente']);
            $b = Sku::create(['industria_id' => $marca->id, 'codigo' => 'B2', 'descricao' => '=Esponja']);
            $c = Sku::create(['industria_id' => $marca->id, 'codigo' => 'C3', 'descricao' => 'Em falta']);
            $visita = Visita::whereDate('data', '2026-10-14')->sole();
            foreach ([[$a, 'tem_estoque'], [$b, 'somente_gondola'], [$c, 'ruptura']] as [$sku, $situacao]) {
                VisitaSku::create(['visita_id' => $visita->id, 'sku_id' => $sku->id, 'situacao_estoque' => $situacao]);
            }
        });

        $this->actingAs($this->admin)->get(self::URL."/promotores/{$this->joao->id}?mes=2026-10")->assertOk()
            ->assertSee('SKUs trabalhados por dia')->assertSee('14/10/2026')
            ->assertViewHas('skusPorDia', fn ($dias) => $dias['2026-10-14'] === ['visitas' => 1, 'skus' => 2, 'itens' => 2]);

        $csv = $this->get(self::URL."/promotores/{$this->joao->id}/skus.csv?mes=2026-10")->assertOk()->streamedContent();
        $this->assertStringContainsString('Detergente', $csv);
        $this->assertStringContainsString("'=Esponja", $csv, 'texto que parece fórmula é protegido');
        $this->assertStringNotContainsString('Em falta', $csv, 'ruptura não conta como trabalhado');
        $this->assertStringContainsString('14/10/2026;Gaboardi', $csv);

        // Mês sem visitas e mês inválido não quebram.
        $this->get(self::URL."/promotores/{$this->joao->id}?mes=2026-01")->assertOk()->assertSee('Nenhum SKU trabalhado neste mês');
        $this->get(self::URL."/promotores/{$this->joao->id}?mes=lixo")->assertOk();
    }

    public function test_editar_e_remover_ocorrencia(): void
    {
        $o = $this->agencia->run(fn () => $this->joao->ocorrencias()->create(['tipo' => 'atraso', 'data' => '2026-10-10', 'descricao' => 'Chegou atrasado']));
        $this->actingAs($this->admin);

        $this->put(self::URL."/promotores/{$this->joao->id}/ocorrencias/{$o->id}", ['tipo' => 'orientacao', 'data' => '2026-10-10', 'descricao' => 'Orientado sobre horário'])->assertRedirect();
        $this->agencia->run(fn () => $this->assertSame('Orientado sobre horário', $o->fresh()->descricao));
        $this->put(self::URL."/promotores/{$this->joao->id}/ocorrencias/{$o->id}", ['tipo' => 'x', 'data' => '2026-10-10', 'descricao' => 'a'])->assertSessionHasErrors('tipo');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->supervisor)->delete(self::URL."/promotores/{$this->joao->id}/ocorrencias/{$o->id}")->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)->delete(self::URL."/promotores/{$this->joao->id}/ocorrencias/{$o->id}")->assertRedirect();
        $this->agencia->run(function () {
            $this->assertSame(0, Ocorrencia::count());
            $this->assertEqualsCanonicalizing(['ocorrencia_editada', 'ocorrencia_removida'], $this->joao->auditoria()->pluck('acao')->all());
        });
    }
}
