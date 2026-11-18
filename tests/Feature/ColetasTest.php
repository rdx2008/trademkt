<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\ColetaResposta;
use App\Models\Industria;
use App\Models\ModeloColeta;
use App\Models\Pdv;
use App\Models\Roteiro;
use App\Models\Sku;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visita;
use App\Services\Coletas\Formulario;
use App\Services\Coletas\ModelosDePartida;
use App\Services\Roteiros\RoteiroDoApp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Fase 10, item 10.1: motor de modelos de coleta (painel, versões e respostas do app). */
class ColetasTest extends TestCase
{
    private const URL = 'http://xyz.test';

    private Tenant $tenant;

    private Industria $marca;

    private Sku $sku;

    private Visita $visita;

    private User $admin;

    private User $promotor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');
        $this->tenant = $this->criarCliente('xyz', 'agencia');
        $agencia = $this->tenant->run(fn () => Agencia::first());
        [$this->marca, $this->sku] = $this->tenant->run(function () use ($agencia) {
            $marca = Industria::create(['nome' => 'Marca Aurora']);
            $pdv = Pdv::create(['cnpj' => self::cnpj(1), 'nome' => 'Mercado Centro']);
            $sku = Sku::create(['industria_id' => $marca->id, 'codigo' => 'A1', 'descricao' => 'Produto']);

            return [$marca, $sku];
        });
        $this->promotor = $this->criarUsuario($this->tenant, Perfil::Promotor, ['agencia_id' => $agencia->id, 'tipo_vinculo' => 'clt']);
        $this->visita = $this->tenant->run(function () use ($agencia) {
            $r = Roteiro::create(['promotor_id' => $this->promotor->id, 'agencia_id' => $agencia->id, 'data' => '2026-10-31']);

            return Visita::create(['roteiro_id' => $r->id, 'data' => '2026-10-31', 'pdv_id' => Pdv::first()->id, 'industria_id' => $this->marca->id, 'agencia_id' => $agencia->id, 'origem' => 'demanda', 'status' => 'em_andamento']);
        });
        $this->admin = $this->tenant->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function modelo(array $campos, array $extra = []): ModeloColeta
    {
        return $this->tenant->run(function () use ($campos, $extra) {
            $m = ModeloColeta::create(['industria_id' => $this->marca->id, 'nome' => 'Validade', 'tipo' => 'validade', 'campos' => Formulario::normalizarCampos($campos), 'versao' => 1] + $extra);
            $m->registrarVersao();

            return $m;
        });
    }

    private function enviar(array $dados)
    {
        Sanctum::actingAs($this->promotor, ['promotor']);

        return $this->postJson(self::URL."/api/v1/visitas/{$this->visita->id}/coletas", $dados + ['uuid' => (string) Str::uuid(), 'respondida_em' => '2026-10-31T11:00:00-03:00']);
    }

    // ----------------------------------------------------------------- regras do formulário

    public function test_campos_sao_normalizados_e_validados(): void
    {
        $campos = Formulario::normalizarCampos([
            ['rotulo' => 'Validade do produto', 'tipo' => 'data', 'obrigatorio' => '1', 'por_sku' => '1'],
            ['rotulo' => 'Validade do produto', 'tipo' => 'texto'],
            ['rotulo' => 'Ação', 'tipo' => 'escolha', 'opcoes' => "Trocar\nRetirar\n\nTrocar"],
            ['rotulo' => '', 'tipo' => 'texto'],
        ]);
        $this->assertSame(['validade_do_produto', 'validade_do_produto_2', 'acao'], array_column($campos, 'chave'));
        $this->assertTrue($campos[0]['obrigatorio'] && $campos[0]['por_sku']);
        $this->assertSame(['Trocar', 'Retirar'], $campos[2]['opcoes']);

        foreach ([[], [['rotulo' => 'X', 'tipo' => 'inventado']], [['rotulo' => 'Y', 'tipo' => 'escolha', 'opcoes' => 'só uma']]] as $ruim) {
            try {
                Formulario::normalizarCampos($ruim);
                $this->fail('deveria recusar');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('campos', $e->errors());
            }
        }
    }

    public function test_respostas_sao_validadas_por_tipo(): void
    {
        $campos = Formulario::normalizarCampos([
            ['rotulo' => 'Preço', 'tipo' => 'preco', 'obrigatorio' => true], ['rotulo' => 'Qtd', 'tipo' => 'quantidade'],
            ['rotulo' => 'Ok', 'tipo' => 'sim_nao'], ['rotulo' => 'Data', 'tipo' => 'data'], ['rotulo' => 'Ação', 'tipo' => 'escolha', 'opcoes' => "A\nB"],
        ]);
        $limpas = Formulario::validar($campos, ['preco' => '4,5' === '4,5' ? 4.5 : 0, 'qtd' => '3', 'ok' => 'sim', 'data' => '2026-12-01', 'acao' => 'A', 'estranho' => 'x'], false);
        $this->assertSame(['preco' => 4.5, 'qtd' => 3, 'ok' => true, 'data' => '2026-12-01', 'acao' => 'A'], $limpas);

        foreach ([['qtd' => 1], ['preco' => -1], ['preco' => 1, 'qtd' => 1.5], ['preco' => 1, 'data' => '01/12/2026'], ['preco' => 1, 'acao' => 'C']] as $ruim) {
            try {
                Formulario::validar($campos, $ruim, false);
                $this->fail('deveria recusar '.json_encode($ruim));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_modelos_de_partida_sao_validos(): void
    {
        foreach (ModelosDePartida::todos() as $chave => $partida) {
            $this->assertNotEmpty(Formulario::normalizarCampos($partida['campos']), $chave);
        }
    }

    // ----------------------------------------------------------------- painel

    public function test_admin_cria_edita_e_versiona_o_modelo(): void
    {
        $this->actingAs($this->admin)->get(self::URL.'/coletas/modelos')->assertOk()->assertSee('Pesquisa de preço');
        $this->get(self::URL.'/coletas/modelos/novo?partida=validade')->assertOk()->assertSee('Validade (produtos a vencer)');

        $this->post(self::URL.'/coletas/modelos', [
            'industria_id' => $this->marca->id, 'nome' => 'Minha validade', 'tipo' => 'validade', 'ativo' => 1,
            'campos' => [['rotulo' => 'Validade', 'tipo' => 'data', 'obrigatorio' => 1, 'por_sku' => 1]],
        ])->assertSessionHasNoErrors()->assertRedirect(self::URL.'/coletas/modelos');
        $m = $this->tenant->run(fn () => ModeloColeta::sole());
        $this->assertSame(1, $m->versao);

        // Só mudou o nome: continua na versão 1. Mudou os campos: versão 2, com a definição antiga guardada.
        $base = ['industria_id' => $this->marca->id, 'tipo' => 'validade', 'ativo' => 1, 'campos' => [['rotulo' => 'Validade', 'tipo' => 'data', 'obrigatorio' => 1, 'por_sku' => 1]]];
        $this->put(self::URL."/coletas/modelos/{$m->id}", ['nome' => 'Renomeado'] + $base)->assertSessionHasNoErrors();
        $this->assertSame(1, $this->tenant->run(fn () => $m->fresh()->versao));
        $this->put(self::URL."/coletas/modelos/{$m->id}", ['nome' => 'Renomeado', 'campos' => [['rotulo' => 'Validade', 'tipo' => 'data', 'por_sku' => 1], ['rotulo' => 'Lote', 'tipo' => 'texto', 'por_sku' => 1]]] + $base)->assertSessionHasNoErrors();
        $this->tenant->run(function () use ($m) {
            $atual = $m->fresh();
            $this->assertSame(2, $atual->versao);
            $this->assertCount(2, $atual->camposDaVersao(2));
            $this->assertCount(1, $atual->camposDaVersao(1), 'a versão antiga continua guardada');
        });

        $this->patch(self::URL."/coletas/modelos/{$m->id}/ativo", ['ativo' => 0])->assertRedirect();
        $this->assertFalse($this->tenant->run(fn () => $m->fresh()->ativo));
        $this->post(self::URL.'/coletas/modelos', ['industria_id' => $this->marca->id, 'nome' => 'Vazio', 'tipo' => 'livre', 'campos' => []])->assertSessionHasErrors('campos');
    }

    public function test_so_gestao_da_industria_cria_modelos(): void
    {
        $this->actingAs($this->criarUsuario($this->tenant, Perfil::Promotor, ['email' => 'p2@xyz.test']))->get(self::URL.'/coletas/modelos')->assertForbidden();
    }

    // ----------------------------------------------------------------- app

    public function test_roteiro_do_app_leva_os_modelos_ativos(): void
    {
        $this->modelo([['rotulo' => 'Validade', 'tipo' => 'data', 'por_sku' => true]]);
        $this->modelo([['rotulo' => 'Inativo', 'tipo' => 'texto']], ['ativo' => false, 'nome' => 'Desligado']);

        $roteiro = $this->tenant->run(fn () => app(RoteiroDoApp::class)->montar($this->promotor, Carbon::parse('2026-10-31')));
        $modelos = $roteiro['industrias'][0]['modelos'];
        $this->assertCount(1, $modelos);
        $this->assertSame(['id', 'nome', 'tipo', 'versao', 'redes', 'campos'], array_keys($modelos[0]));
        $this->assertSame('Validade', $modelos[0]['nome']);
        $this->assertArrayHasKey('rede_id', $roteiro['visitas'][0]['pdv']);
    }

    public function test_app_envia_respostas_geral_e_por_sku_de_forma_idempotente(): void
    {
        $m = $this->modelo([
            ['rotulo' => 'Validade', 'tipo' => 'data', 'obrigatorio' => true, 'por_sku' => true],
            ['rotulo' => 'Quantidade', 'tipo' => 'quantidade', 'por_sku' => true],
            ['rotulo' => 'Observação', 'tipo' => 'texto'],
        ]);

        $porSku = ['modelo_id' => $m->id, 'versao' => 1, 'sku_id' => $this->sku->id, 'respostas' => ['validade' => '2026-12-31', 'quantidade' => '4']];
        $primeira = $this->enviar($porSku + ['uuid' => '11111111-1111-4111-8111-111111111111'])->assertCreated();
        $this->assertFalse($primeira->json('repetida'));
        $this->enviar($porSku + ['uuid' => '11111111-1111-4111-8111-111111111111'])->assertOk()->assertJson(['repetida' => true]);
        $this->enviar(['respostas' => ['validade' => '2027-01-31']] + $porSku + ['uuid' => '11111111-1111-4111-8111-111111111111'])->assertStatus(409);

        $this->enviar(['modelo_id' => $m->id, 'versao' => 1, 'respostas' => ['observacao' => 'Tudo certo']])->assertCreated();

        $this->tenant->run(function () {
            $this->assertSame(2, ColetaResposta::count());
            $r = ColetaResposta::whereNotNull('sku_id')->sole();
            $this->assertSame(['validade' => '2026-12-31', 'quantidade' => 4], $r->respostas);
        });
        $this->actingAs($this->admin)->get(self::URL."/visitas/{$this->visita->id}")->assertOk()->assertSee('Coletas')->assertSee('2026-12-31')->assertSee('Tudo certo');
    }

    public function test_resposta_invalida_ou_de_outro_promotor_e_recusada(): void
    {
        $m = $this->modelo([['rotulo' => 'Validade', 'tipo' => 'data', 'obrigatorio' => true, 'por_sku' => true]]);
        $this->enviar(['modelo_id' => $m->id, 'versao' => 1, 'sku_id' => $this->sku->id, 'respostas' => []])->assertStatus(422);
        $this->enviar(['modelo_id' => $m->id, 'versao' => 1, 'sku_id' => $this->sku->id, 'respostas' => ['validade' => 'amanhã']])->assertStatus(422);
        $this->enviar(['modelo_id' => $m->id, 'versao' => 9, 'sku_id' => $this->sku->id, 'respostas' => ['validade' => '2026-12-31']])->assertStatus(422);
        $this->enviar(['modelo_id' => $m->id, 'versao' => 1, 'sku_id' => 9999, 'respostas' => ['validade' => '2026-12-31']])->assertStatus(422);

        $outro = $this->criarUsuario($this->tenant, Perfil::Promotor, ['email' => 'outro@xyz.test', 'agencia_id' => $this->tenant->run(fn () => Agencia::first()->id)]);
        Sanctum::actingAs($outro, ['promotor']);
        $this->postJson(self::URL."/api/v1/visitas/{$this->visita->id}/coletas", ['uuid' => (string) Str::uuid(), 'modelo_id' => $m->id, 'versao' => 1, 'sku_id' => $this->sku->id, 'respostas' => ['validade' => '2026-12-31'], 'respondida_em' => '2026-10-31T11:00:00-03:00'])->assertNotFound();
        $this->assertSame(0, $this->tenant->run(fn () => ColetaResposta::count()));
    }

    public function test_resposta_antiga_valida_contra_a_versao_em_que_foi_dada(): void
    {
        $m = $this->modelo([['rotulo' => 'Validade', 'tipo' => 'data', 'por_sku' => true]]);
        $this->tenant->run(function () use ($m) {
            $m->campos = Formulario::normalizarCampos([['rotulo' => 'Validade', 'tipo' => 'data', 'por_sku' => true], ['rotulo' => 'Lote', 'tipo' => 'texto', 'obrigatorio' => true, 'por_sku' => true]]);
            $m->versao = 2;
            $m->save();
            $m->registrarVersao();
        });
        // O app ainda estava na versão 1 (sem o campo "Lote" obrigatório): a resposta vale.
        $this->enviar(['modelo_id' => $m->id, 'versao' => 1, 'sku_id' => $this->sku->id, 'respostas' => ['validade' => '2026-12-31']])->assertCreated();
        // Na versão 2, o lote passa a ser obrigatório.
        $this->enviar(['modelo_id' => $m->id, 'versao' => 2, 'sku_id' => $this->sku->id, 'respostas' => ['validade' => '2026-12-31']])->assertStatus(422);
    }
}
