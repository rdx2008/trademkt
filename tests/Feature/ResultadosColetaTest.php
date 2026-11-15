<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\Alerta;
use App\Models\ColetaResposta;
use App\Models\Industria;
use App\Models\ModeloColeta;
use App\Models\Pdv;
use App\Models\Rede;
use App\Models\Roteiro;
use App\Models\Sku;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visita;
use App\Services\Coletas\Formulario;
use App\Services\Coletas\ModelosDePartida;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Fase 10 (10.2 a 10.5): preço, validade, estoque e share, com alerta de validade e CSV. */
class ResultadosColetaTest extends TestCase
{
    private const URL = 'http://xyz.test';

    private Tenant $tenant;

    private Industria $marca;

    private Sku $alvejante;

    private Sku $detergente;

    private Visita $visitaA;

    private Visita $visitaB;

    private User $admin;

    private User $promotor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');
        $this->tenant = $this->criarCliente('xyz', 'agencia');
        $agencia = $this->tenant->run(fn () => Agencia::first());
        $this->promotor = $this->criarUsuario($this->tenant, Perfil::Promotor, ['agencia_id' => $agencia->id, 'tipo_vinculo' => 'clt']);
        $this->admin = $this->tenant->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());

        $this->tenant->run(function () use ($agencia) {
            $this->marca = Industria::create(['nome' => 'Marca Aurora']);
            $this->alvejante = Sku::create(['industria_id' => $this->marca->id, 'codigo' => 'ALG-25', 'descricao' => 'Alvejante 2 L']);
            $this->detergente = Sku::create(['industria_id' => $this->marca->id, 'codigo' => 'DET-1', 'descricao' => 'Detergente']);
            $rede1 = Rede::create(['nome' => 'Rede Sul']);
            $rede2 = Rede::create(['nome' => 'Rede Norte']);
            $pdvA = Pdv::create(['cnpj' => self::cnpj(1), 'nome' => 'Loja A', 'cidade' => 'Caxias do Sul', 'uf' => 'RS', 'rede_id' => $rede1->id]);
            $pdvB = Pdv::create(['cnpj' => self::cnpj(2), 'nome' => 'Loja B', 'cidade' => 'Manaus', 'uf' => 'AM', 'rede_id' => $rede2->id]);
            $r = Roteiro::create(['promotor_id' => $this->promotor->id, 'agencia_id' => $agencia->id, 'data' => '2026-10-31']);
            $nova = fn (Pdv $pdv) => Visita::create(['roteiro_id' => $r->id, 'data' => '2026-10-31', 'pdv_id' => $pdv->id, 'industria_id' => $this->marca->id, 'agencia_id' => $agencia->id, 'origem' => 'demanda', 'status' => 'em_andamento']);
            $this->visitaA = $nova($pdvA);
            $this->visitaB = $nova($pdvB);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function modelo(string $partida, array $extra = []): ModeloColeta
    {
        $p = ModelosDePartida::todos()[$partida];

        return $this->tenant->run(function () use ($p, $extra) {
            $m = ModeloColeta::create(['industria_id' => $this->marca->id, 'nome' => $p['nome'], 'tipo' => $p['tipo'], 'campos' => Formulario::normalizarCampos($p['campos']), 'versao' => 1] + $extra);
            $m->registrarVersao();

            return $m;
        });
    }

    private function responder(ModeloColeta $m, Visita $v, ?Sku $sku, array $campos, ?Carbon $quando = null): void
    {
        $this->tenant->run(function () use ($m, $v, $sku, $campos, $quando) {
            $respostas = [];
            foreach ($campos as $papel => $valor) {
                $respostas[$m->chaveDoPapel($papel)] = $valor;
            }
            ColetaResposta::create(['uuid' => (string) Str::uuid(), 'visita_id' => $v->id, 'modelo_id' => $m->id, 'versao' => 1, 'sku_id' => $sku?->id,
                'respostas' => $respostas, 'respondida_em' => $quando ?? now(), 'recebida_em' => now(), 'hash' => Str::random(64)]);
        });
    }

    public function test_modelos_de_partida_tem_papeis_para_os_relatorios(): void
    {
        $this->assertSame('preco_de_prateleira', $this->modelo('preco')->chaveDoPapel('preco_proprio'));
        $this->assertNotNull($this->modelo('validade')->chaveDoPapel('validade'));
        $this->assertNotNull($this->modelo('estoque')->chaveDoPapel('estoque_gondola'));
        $this->assertNotNull($this->modelo('share')->chaveDoPapel('frentes_marca'));
    }

    public function test_preco_por_produto_e_rede_com_comparativo_do_concorrente(): void
    {
        $m = $this->modelo('preco');
        $this->responder($m, $this->visitaA, $this->alvejante, ['preco_proprio' => 10.0]);
        $this->responder($m, $this->visitaA, $this->detergente, ['preco_proprio' => 3.5]);
        $this->responder($m, $this->visitaA, null, ['preco_concorrente' => 9.0]);
        $this->responder($m, $this->visitaB, $this->alvejante, ['preco_proprio' => 12.0]);

        $this->actingAs($this->admin)->get(self::URL.'/coletas/resultados?tipo=preco')->assertOk()
            ->assertSee('ALG-25 · Alvejante 2 L')->assertSee('Rede Sul')->assertSee('Rede Norte')
            ->assertSee('Rede Sul: nosso médio R$ 6.75 · concorrente R$ 9 · diferença R$ -2.25')
            ->assertSee('Produtos pesquisados: ')->assertSee('2');

        // Filtro por rede.
        $rede = $this->tenant->run(fn () => Rede::where('nome', 'Rede Norte')->value('id'));
        $this->get(self::URL."/coletas/resultados?tipo=preco&rede_id={$rede}")->assertOk()->assertSee('Rede Norte')->assertDontSee('Rede Sul: nosso');
    }

    public function test_validade_mostra_a_vencer_na_janela_e_o_alerta_chega_a_gestao(): void
    {
        $m = $this->modelo('validade', ['alerta_dias' => 30]);
        $this->responder($m, $this->visitaA, $this->alvejante, ['validade' => '2026-11-10', 'quantidade_vencimento' => 4, 'lote' => 'L1']);
        $this->responder($m, $this->visitaA, $this->detergente, ['validade' => '2026-10-20', 'quantidade_vencimento' => 1]);
        $this->responder($m, $this->visitaB, $this->alvejante, ['validade' => '2027-06-01', 'quantidade_vencimento' => 9]);

        $this->actingAs($this->admin)->get(self::URL.'/coletas/resultados?tipo=validade&dias=60')->assertOk()
            ->assertSee('2026-11-10')->assertSee('L1')->assertSee('2026-10-20')->assertDontSee('2027-06-01')
            ->assertSee('Já vencidos')->assertSee('Produtos a vencer em até 60 dias');
        $this->get(self::URL.'/coletas/resultados?tipo=validade&dias=5')->assertOk()->assertSee('2026-10-20')->assertDontSee('2026-11-10');

        // Pela API: validade dentro do alerta gera aviso para a gestão; fora da janela, não.
        Sanctum::actingAs($this->promotor, ['promotor']);
        $campoValidade = $m->chaveDoPapel('validade');
        $campoQtd = $m->chaveDoPapel('quantidade_vencimento');
        $envio = fn (Visita $v, string $data) => $this->postJson(self::URL."/api/v1/visitas/{$v->id}/coletas", [
            'uuid' => (string) Str::uuid(), 'modelo_id' => $m->id, 'versao' => 1, 'sku_id' => $this->alvejante->id,
            'respostas' => [$campoValidade => $data, $campoQtd => 2], 'respondida_em' => '2026-10-31T11:00:00-03:00',
        ])->assertCreated();
        $envio($this->visitaB, '2027-06-01');
        $this->assertSame(0, $this->tenant->run(fn () => Alerta::where('tipo', 'validade_proxima')->count()));
        $envio($this->visitaA, '2026-11-15');
        $this->tenant->run(function () {
            $alerta = Alerta::where('tipo', 'validade_proxima')->first();
            $this->assertNotNull($alerta);
            $this->assertStringContainsString('Loja A', $alerta->mensagem);
            $this->assertStringContainsString('15/11/2026', $alerta->mensagem);
        });
    }

    public function test_estoque_usa_o_ultimo_levantamento_e_conta_gondolas_vazias(): void
    {
        $m = $this->modelo('estoque');
        $this->responder($m, $this->visitaA, $this->alvejante, ['estoque_gondola' => 10, 'estoque_loja' => 5, 'estoque_deposito' => 20]);
        $this->responder($m, $this->visitaA, $this->alvejante, ['estoque_gondola' => 0, 'estoque_loja' => 2, 'estoque_deposito' => 20], now()->addHour()); // mais recente
        $this->responder($m, $this->visitaB, $this->alvejante, ['estoque_gondola' => 8, 'estoque_loja' => 0, 'estoque_deposito' => 0]);

        $r = $this->actingAs($this->admin)->get(self::URL.'/coletas/resultados?tipo=estoque')->assertOk()->assertSee('Gôndolas vazias');
        $r->assertViewHas('relatorio', function ($rel) {
            // Produto, lojas, gôndola, loja, depósito, lojas com gôndola vazia (só o último de cada loja conta).
            return $rel['linhas'] === [['ALG-25 · Alvejante 2 L', 2, 8, 2, 20, 1]];
        });
    }

    public function test_share_por_rede_e_geral(): void
    {
        $m = $this->modelo('share');
        $this->responder($m, $this->visitaA, null, ['frentes_marca' => 6, 'frentes_concorrentes' => 4]);
        $this->responder($m, $this->visitaB, null, ['frentes_marca' => 2, 'frentes_concorrentes' => 8]);

        $this->actingAs($this->admin)->get(self::URL.'/coletas/resultados?tipo=share')->assertOk()
            ->assertViewHas('relatorio', fn ($rel) => $rel['linhas'][0] === ['Rede Sul', 1, 6, 4, 60.0] && $rel['linhas'][1] === ['Rede Norte', 1, 2, 8, 20.0]
                && $rel['resumo'][0] === ['Share geral (%)', 40.0]);
    }

    public function test_csv_e_permissoes(): void
    {
        $m = $this->modelo('preco');
        $this->tenant->run(fn () => $this->alvejante->update(['codigo' => '=1+1']));
        $this->responder($m, $this->visitaA, $this->alvejante, ['preco_proprio' => 10.0]);

        $csv = $this->actingAs($this->admin)->get(self::URL.'/coletas/resultados.csv?tipo=preco')->assertOk()->streamedContent();
        $this->assertStringContainsString('Produto;Rede;Lojas', $csv);
        $this->assertStringContainsString("'=1+1 · Alvejante 2 L", $csv, 'texto que começa com = não vira fórmula');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->promotor)->get(self::URL.'/coletas/resultados')->assertForbidden();
        $this->get(self::URL.'/coletas/resultados?tipo=inventado')->assertForbidden();
    }

    public function test_alerta_de_validade_exige_papel_e_janela_no_modelo(): void
    {
        $semAlerta = $this->modelo('validade');
        Sanctum::actingAs($this->promotor, ['promotor']);
        $this->postJson(self::URL."/api/v1/visitas/{$this->visitaA->id}/coletas", [
            'uuid' => (string) Str::uuid(), 'modelo_id' => $semAlerta->id, 'versao' => 1, 'sku_id' => $this->alvejante->id,
            'respostas' => [$semAlerta->chaveDoPapel('validade') => '2026-11-01', $semAlerta->chaveDoPapel('quantidade_vencimento') => 1], 'respondida_em' => '2026-10-31T11:00:00-03:00',
        ])->assertCreated();
        $this->assertSame(0, $this->tenant->run(fn () => Alerta::where('tipo', 'validade_proxima')->count()));
    }
}
