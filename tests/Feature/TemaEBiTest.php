<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Admin;
use App\Models\Agencia;
use App\Models\Industria;
use App\Models\Pdv;
use App\Models\Roteiro;
use App\Models\Sku;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visita;
use App\Models\VisitaSku;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Tema novo das telas dos clientes, menu lateral em tópicos e BI da indústria/agência.
 * O painel do super admin (central) mantém o visual anterior.
 */
class TemaEBiTest extends TestCase
{
    private const URL = 'http://xyz.test';

    private Tenant $tenant;

    private Agencia $agencia;

    private Industria $marcaA;

    private Industria $marcaB;

    private User $promotor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');

        $this->tenant = $this->criarCliente('xyz', 'agencia');
        [$this->agencia, $this->marcaA, $this->marcaB] = $this->tenant->run(fn () => [
            Agencia::first(),
            Industria::create(['nome' => 'Marca A']),
            Industria::create(['nome' => 'Marca B']),
        ]);
        $this->promotor = $this->criarUsuario($this->tenant, Perfil::Promotor, ['nome' => 'João Silva', 'agencia_id' => $this->agencia->id, 'tipo_vinculo' => 'clt']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return $this->tenant->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->first());
    }

    /** Cria uma visita de contrato (com PDV e roteiro) e, se pedido, os SKUs verificados nela. */
    private function visita(Industria $marca, string $data, string $status, array $extra = [], array $itens = []): Visita
    {
        return $this->tenant->run(function () use ($marca, $data, $status, $extra, $itens) {
            $pdv = Pdv::firstOrCreate(['cnpj' => self::cnpj(1)], ['nome' => 'Mercado Centro', 'cidade' => 'Caxias do Sul', 'uf' => 'RS', 'lat' => -29.168, 'lng' => -51.179]);
            $roteiro = Roteiro::where('promotor_id', $this->promotor->id)->whereDate('data', $data)->first()
                ?? Roteiro::create(['promotor_id' => $this->promotor->id, 'data' => $data]);

            $visita = Visita::forceCreate(array_merge([
                'roteiro_id' => $roteiro->id, 'data' => $data, 'data_prevista' => $data, 'agencia_id' => $this->agencia->id,
                'pdv_id' => $pdv->id, 'industria_id' => $marca->id, 'origem' => 'contrato', 'status' => $status,
            ], $extra));

            foreach ($itens as $i => $estoque) {
                $sku = Sku::firstOrCreate(['industria_id' => $marca->id, 'codigo' => 'S'.($i + 1)], ['descricao' => 'Produto '.($i + 1)]);
                VisitaSku::create(['visita_id' => $visita->id, 'sku_id' => $sku->id, 'situacao_estoque' => $estoque]);
            }

            return $visita;
        });
    }

    private function concluida(string $dia, string $tipo = 'normal'): array
    {
        return ['tipo_conclusao' => $tipo, 'concluida_em' => "{$dia} 10:00:00", 'recebida_em' => "{$dia} 10:01:00", 'duracao_segundos' => 1800];
    }

    private function dados(): void
    {
        // Período atual (02/10 a 31/10): 4 da marca A e 1 da marca B.
        $this->visita($this->marcaA, '2026-10-20', 'realizada', $this->concluida('2026-10-20'), ['tem_estoque', 'somente_gondola']);
        $this->visita($this->marcaA, '2026-10-21', 'realizada', $this->concluida('2026-10-21', 'ruptura'), ['ruptura']);
        $this->visita($this->marcaA, '2026-10-22', 'nao_executada');
        $this->visita($this->marcaA, '2026-10-23', 'em_atencao', $this->concluida('2026-10-23', 'impedimento') + ['observacao' => 'Loja fechada']);
        $this->visita($this->marcaB, '2026-10-24', 'realizada', $this->concluida('2026-10-24'), ['tem_estoque']);
        // Período anterior (02/09 a 01/10): uma realizada.
        $this->visita($this->marcaA, '2026-09-10', 'realizada', $this->concluida('2026-09-10'), ['tem_estoque']);
    }

    // ------------------------------------------------------------------ tema e menu

    public function test_telas_dos_clientes_usam_o_tema_novo_e_o_central_mantem_o_visual(): void
    {
        $this->actingAs($this->admin())->get(self::URL.'/painel')
            ->assertOk()
            ->assertSee('tema/css/style.css', false)
            ->assertSee('class="deznav"', false)
            ->assertSee('tema/vendor/global/global.min.js', false)
            ->assertDontSee('class="topo"', false);

        $this->app['auth']->forgetGuards();
        $central = Admin::create(['nome' => 'Rodolfo', 'email' => 'r@mastervps.test', 'password' => 'senha1234']);
        $this->actingAs($central, 'admin')->get('http://central.test/visao-geral')
            ->assertOk()
            ->assertSee('css/app.css', false)
            ->assertDontSee('tema/css/style.css', false);
    }

    public function test_login_do_cliente_usa_o_cartao_do_tema_sem_menu(): void
    {
        $this->get(self::URL.'/entrar')
            ->assertOk()
            ->assertSee('tema/css/style.css', false)
            ->assertSee('Entre com seu e-mail e senha.')
            ->assertDontSee('class="deznav"', false);
    }

    public function test_menu_lateral_agrupa_em_topicos_com_submenu(): void
    {
        $this->actingAs($this->admin())->get(self::URL.'/painel')
            ->assertOk()
            ->assertSeeInOrder(['Visão geral', 'Operação', 'Roteiros', 'Contratos de visita', 'Demandas e rupturas', 'Resultados', 'Indicadores e fotos', 'BI da agência', 'Cadastros', 'PDVs', 'Catálogo de SKUs', 'Importar produtos', 'Agências', 'Regiões', 'Redes de supermercados', 'Usuários', 'Meus alertas'])
            ->assertSee('class="has-arrow ai-icon"', false);
    }

    public function test_menu_so_mostra_o_que_o_perfil_pode_abrir(): void
    {
        $this->actingAs($this->promotor)->get(self::URL.'/painel')
            ->assertOk()
            ->assertSee('Minha agenda')
            ->assertSee('Demandas e rupturas')
            ->assertSee('Minha produção')
            ->assertDontSee('Roteiros')
            ->assertDontSee('Importar produtos')
            ->assertDontSee('Usuários')
            ->assertDontSee('BI da agência');

        $representante = $this->criarUsuario($this->tenant, Perfil::Representante, ['industria_id' => $this->marcaA->id]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($representante)->get(self::URL.'/painel')
            ->assertOk()
            ->assertSee('Minha carteira')
            ->assertSee('Catálogo de SKUs')
            ->assertDontSee('Importar produtos')
            ->assertDontSee('BI da');
    }

    public function test_topico_com_um_unico_item_vira_link_direto(): void
    {
        $gerente = $this->criarUsuario($this->tenant, Perfil::GerentePdv);

        $this->actingAs($gerente)->get(self::URL.'/painel')
            ->assertOk()
            ->assertSee('Minhas lojas')
            ->assertSee('Demandas e rupturas')
            ->assertSee('PDVs')
            ->assertDontSee('Catálogo de SKUs')
            ->assertDontSee('Importar produtos')
            ->assertDontSee('has-arrow');
    }

    public function test_modo_escuro_e_icones_do_tema_estao_no_layout(): void
    {
        $this->actingAs($this->admin())->get(self::URL.'/painel')
            ->assertSee('id="alternar-tema"', false)
            ->assertSee('trade-tema', false)
            ->assertSee('flaticon-381-home-2', false)
            ->assertSee('data-grafico=', false)
            ->assertSee('tema/vendor/apexchart/apexchart.js', false);
    }

    // ------------------------------------------------------------------------- BI

    public function test_bi_so_para_gestao_agencia_e_marca(): void
    {
        $this->actingAs($this->admin())->get(self::URL.'/bi')->assertOk()->assertSee('BI da agência');

        foreach ([Perfil::Promotor, Perfil::Representante, Perfil::GerentePdv] as $perfil) {
            $usuario = $this->criarUsuario($this->tenant, $perfil, ['email' => $perfil->value.'2@xyz.test', 'industria_id' => $this->marcaA->id, 'agencia_id' => $this->agencia->id]);
            $this->app['auth']->forgetGuards();
            $this->actingAs($usuario)->get(self::URL.'/bi')->assertForbidden();
        }

        $supervisor = $this->criarUsuario($this->tenant, Perfil::Supervisor, ['agencia_id' => $this->agencia->id]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($supervisor)->get(self::URL.'/bi')->assertOk();
    }

    public function test_bi_da_agencia_calcula_indicadores_e_compara_com_o_periodo_anterior(): void
    {
        $this->dados();

        $this->actingAs($this->admin())->get(self::URL.'/bi')
            ->assertOk()
            ->assertSee('BI da agência')
            ->assertSee('Produtividade dos promotores')
            ->assertSee('João Silva')
            ->assertSee('Atendimento por indústria cliente')
            ->assertSee('PDVs que pedem atenção')
            ->assertViewHas('bi', function (array $bi) {
                $kpi = collect($bi['kpis'])->keyBy('rotulo');

                $this->assertSame('agencia', $bi['visao']);
                $this->assertSame('2026-10-02', $bi['filtros']['inicio']);
                $this->assertSame('2026-10-31', $bi['filtros']['fim']);

                $this->assertSame('5', $kpi['Visitas na agenda']['valor']);
                $this->assertSame('3', $kpi['Visitas realizadas']['valor']);
                $this->assertSame('1', $kpi['Visitas não executadas']['valor']);
                $this->assertSame('1', $kpi['Impedimentos de acesso']['valor']);
                $this->assertSame('1', $kpi['Rupturas registradas']['valor']);
                $this->assertSame('3', $kpi['SKUs trabalhados']['valor'], 'ruptura e impedimento não contam como SKU trabalhado');
                $this->assertSame('30 min', $kpi['Tempo médio de execução']['valor']);
                $this->assertSame('60%', $kpi['Cobertura dos contratos']['valor']);

                // Período anterior: 1 de 1 (100%). Cobertura caiu 40 pontos: piora.
                $this->assertSame(-40.0, $kpi['Cobertura dos contratos']['delta']);
                $this->assertFalse($kpi['Cobertura dos contratos']['melhorou']);
                $this->assertSame('+2', $kpi['Visitas realizadas']['delta_texto']);
                $this->assertTrue($kpi['Visitas realizadas']['melhorou']);
                $this->assertFalse($kpi['Visitas não executadas']['melhorou'] ?? false, 'mais não executadas é pior');

                // Estoque: 1 tem, 1 somente gôndola, 1 ruptura, mais 1 tem (marca B).
                $this->assertSame([2, 1, 1], $bi['estoque']['dados']);
                $this->assertSame(4, $bi['estoque']['total']);

                // Rankings e recortes.
                $this->assertSame(['Marca A', 'Marca B'], $bi['porIndustria']->pluck('nome')->sort()->values()->all());
                $this->assertSame(5, $bi['porPromotor']->sole()->total);
                $this->assertSame(3, $bi['porPromotor']->sole()->skus);
                $this->assertSame('Mercado Centro', $bi['pdvsCriticos']->sole()->nome);
                $this->assertSame(2, $bi['pdvsCriticos']->sole()->nao_executadas + $bi['pdvsCriticos']->sole()->impedimentos);
                $this->assertSame(1, $bi['rupturasPorPdv']->sole()->total);
                $this->assertCount(1, $bi['topRupturasSku']);
                $this->assertSame(['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'], $bi['porDiaSemana']['categorias']);
                $this->assertSame(3, array_sum($bi['porDiaSemana']['dados']));

                // Tendência diária: 30 dias, soma das situações = visitas da agenda.
                $this->assertCount(30, $bi['tendencia']['categorias']);
                $this->assertSame(5, array_sum(array_map('array_sum', array_column($bi['tendencia']['series'], 'dados'))));

                return true;
            });
    }

    public function test_bi_da_industria_mostra_so_a_marca_do_gerente_de_trade(): void
    {
        $this->dados();
        $gerente = $this->criarUsuario($this->tenant, Perfil::GerenteTrade, ['industria_id' => $this->marcaA->id]);

        $this->actingAs($gerente)->get(self::URL.'/bi')
            ->assertOk()
            ->assertSee('BI da indústria')
            ->assertSee('Desempenho por agência prestadora')
            ->assertDontSee('Produtividade dos promotores')
            ->assertDontSee('Marca B')
            ->assertViewHas('bi', function (array $bi) {
                $kpi = collect($bi['kpis'])->keyBy('rotulo');

                $this->assertSame('industria', $bi['visao']);
                $this->assertSame('4', $kpi['Visitas na agenda']['valor'], 'a visita da marca B não entra');
                $this->assertSame('2', $kpi['Visitas realizadas']['valor']);
                $this->assertSame(1, $bi['porAgencia']->count());
                $this->assertSame(4, $bi['porAgencia']->first()->total);
                $this->assertArrayNotHasKey('porPromotor', $bi);

                return true;
            });
    }

    public function test_bi_aplica_filtros_e_valida_o_periodo(): void
    {
        $this->dados();
        $this->actingAs($this->admin());

        $this->get(self::URL.'/bi?industria_id='.$this->marcaB->id)->assertOk()
            ->assertViewHas('bi', fn ($bi) => collect($bi['kpis'])->firstWhere('rotulo', 'Visitas na agenda')['valor'] === '1');

        // Período longo agrupa por semana.
        $this->get(self::URL.'/bi?inicio=2026-01-01&fim=2026-10-31')->assertOk()
            ->assertViewHas('bi', fn ($bi) => $bi['tendencia']['agrupamento'] === 'semana')
            ->assertSee('por semana');

        $this->get(self::URL.'/bi?inicio=2026-10-31&fim=2026-10-01')->assertSessionHasErrors('fim');
        $this->get(self::URL.'/bi?industria_id=999999')->assertSessionHasErrors('industria_id');
    }

    public function test_bi_sem_dados_nao_quebra(): void
    {
        $this->actingAs($this->admin())->get(self::URL.'/bi')
            ->assertOk()
            ->assertSee('Nenhum SKU verificado no período.')
            ->assertSee('Nenhuma ruptura registrada no período.')
            ->assertSee('Nenhum PDV com visita não executada ou impedimento no período.')
            ->assertViewHas('bi', fn ($bi) => collect($bi['kpis'])->firstWhere('rotulo', 'Cobertura dos contratos')['valor'] === '—');
    }

    public function test_bi_da_instalacao_de_industria_mostra_a_visao_da_marca(): void
    {
        $tenant = $this->criarCliente('gaboardi', 'industria');
        $admin = $tenant->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->first());

        $this->actingAs($admin)->get('http://gaboardi.test/bi')
            ->assertOk()
            ->assertSee('BI da indústria')
            ->assertSee('Desempenho por agência prestadora');
        $this->actingAs($admin)->get('http://gaboardi.test/painel')->assertSee('BI da indústria');
    }
}
