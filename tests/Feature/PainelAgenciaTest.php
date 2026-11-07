<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\Alerta;
use App\Models\ContratoVisita;
use App\Models\Demanda;
use App\Models\Industria;
use App\Models\Pdv;
use App\Models\Roteiro;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visita;
use App\Services\Roteiros\GeradorVisitas;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A agência entra na própria instalação e trabalha dentro da operação das indústrias
 * vinculadas; o promotor entra no app pela agência e recebe as visitas de todas elas.
 */
class PainelAgenciaTest extends TestCase
{
    private const SUL = 'http://sul.test';

    private Tenant $gaboardi;

    private Tenant $acme;

    private Tenant $sul;

    private Agencia $localGaboardi;

    private Agencia $localAcme;

    private User $adminSul;

    private User $ana;

    private User $joao;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00'); // quarta
        $this->gaboardi = $this->criarCliente('gaboardi');
        $this->acme = $this->criarCliente('acme');
        $this->sul = $this->criarCliente('sul', 'agencia');
        $this->localGaboardi = $this->vincular($this->gaboardi, $this->sul, [['uf' => 'RS']]);
        $this->localAcme = $this->vincular($this->acme, $this->sul);

        $propria = $this->sul->run(fn () => Agencia::first()->id);
        $this->adminSul = $this->sul->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
        $this->ana = $this->criarUsuario($this->sul, Perfil::Supervisor, ['nome' => 'Ana Supervisora', 'email' => 'ana@sul.test', 'agencia_id' => $propria]);
        $this->joao = $this->criarUsuario($this->sul, Perfil::Promotor, ['nome' => 'João da Silva', 'email' => 'joao@sul.test', 'agencia_id' => $propria, 'tipo_vinculo' => 'clt']);

        foreach ([[$this->gaboardi, $this->localGaboardi, 'Super Centro', 1], [$this->acme, $this->localAcme, 'Mercado Acme', 2]] as [$tenant, $local, $nome, $n]) {
            $tenant->run(function () use ($local, $nome, $n) {
                $pdv = Pdv::create(['cnpj' => self::cnpj($n), 'nome' => $nome, 'cidade' => 'Caxias do Sul', 'uf' => 'RS', 'lat' => -29.168, 'lng' => -51.179]);
                ContratoVisita::create(['pdv_id' => $pdv->id, 'industria_id' => Industria::first()->id, 'agencia_id' => $local->id, 'frequencia_semana' => 1, 'dias' => [3], 'inicio' => '2026-10-01']);
                app(GeradorVisitas::class)->gerar(Carbon::parse('2026-10-12'), Carbon::parse('2026-10-18'));
            });
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_da_agencia_abre_a_operacao_das_industrias_vinculadas_e_troca_entre_elas(): void
    {
        $this->actingAs($this->adminSul)->get(self::SUL.'/painel')->assertOk()
            ->assertSee('Indústria atendida')->assertSee('seletor-operacao', false)->assertSee('Acme')->assertDontSee('Importar produtos')
            ->assertSee('Mercado Acme')->assertDontSee('Super Centro') // primeira indústria por nome
            ->assertSee('Sul'); // a marca é a da agência

        $this->post(self::SUL.'/operacao/gaboardi')->assertRedirect(self::SUL.'/painel');
        $this->get(self::SUL.'/painel')->assertOk()->assertSee('Super Centro')->assertDontSee('Mercado Acme');
        $this->get(self::SUL.'/pdvs')->assertOk()->assertSee('Super Centro')->assertViewHas('podeEditar', false);
        $this->get(self::SUL.'/skus')->assertOk();
        $this->get(self::SUL.'/contratos')->assertOk()->assertSee('Super Centro')->assertViewHas('podeCadastrar', false);
        $this->get(self::SUL.'/regioes')->assertOk();

        // Cadastros da indústria não são da agência.
        $this->get(self::SUL.'/pdvs/create')->assertForbidden();
        $this->get(self::SUL.'/industrias')->assertForbidden();
        $this->get(self::SUL.'/skus/importar')->assertForbidden();
        $this->post(self::SUL.'/operacao/outra')->assertNotFound();

        // A equipe continua na própria agência.
        $this->get(self::SUL.'/usuarios')->assertOk()->assertSee('Ana Supervisora')->assertDontSee('João da Silva');
        $this->get(self::SUL.'/promotores')->assertOk()->assertSee('João da Silva');
        $this->sul->run(fn () => $this->assertSame(Perfil::AdminInstalacao, $this->adminSul->fresh()->perfil, 'a apresentação como admin da agência não é gravada'));
        $this->gaboardi->run(fn () => $this->assertSame(0, User::where('perfil', 'promotor')->count()));
    }

    public function test_login_real_da_agencia_abre_o_painel_e_a_sessao_nao_vale_em_outra_instalacao(): void
    {
        $this->get(self::SUL.'/entrar')->assertOk();
        $this->post(self::SUL.'/entrar', ['email' => 'admin@sul.test', 'password' => 'senha1234'])->assertRedirect(self::SUL.'/painel');
        foreach (['/painel', '/roteiros', '/pdvs', '/alertas', '/bi', '/contratos', '/usuarios'] as $caminho) {
            $this->get(self::SUL.$caminho)->assertOk();
        }
        // O mesmo cookie não vale na instalação da indústria.
        $this->get('http://gaboardi.test/painel')->assertStatus(403);
    }

    public function test_supervisora_atribui_promotor_aprova_demanda_e_recebe_alertas_na_agencia(): void
    {
        $visita = $this->gaboardi->run(fn () => Visita::sole());
        $this->actingAs($this->ana)->post(self::SUL.'/operacao/gaboardi');
        $this->get(self::SUL.'/roteiros?semana=2026-10-12')->assertOk()->assertViewHas('podeEditar', true)->assertSee('João da Silva');
        $this->putJson(self::SUL."/visitas/{$visita->id}/mover", ['promotor_id' => $this->joao->id, 'data' => '2026-10-14'])->assertOk();
        $this->gaboardi->run(function () {
            $roteiro = Roteiro::sole();
            $this->assertSame([$this->localGaboardi->id, $this->joao->id, $this->ana->id], [$roteiro->agencia_id, $roteiro->promotor_id, $roteiro->supervisor_id]);
        });
        $this->get(self::SUL."/visitas/{$visita->id}")->assertOk()->assertSee('João da Silva');

        // O gerente da loja pede uma visita na indústria; a supervisora decide pela agência.
        $gerente = $this->criarUsuario($this->gaboardi, Perfil::GerentePdv);
        $contrato = $this->gaboardi->run(function () use ($gerente) {
            $gerente->pdvsGerenciados()->attach(Pdv::first()->id);

            return ContratoVisita::first();
        });
        $this->app['auth']->forgetGuards();
        $this->actingAs($gerente)->post('http://gaboardi.test/demandas', ['uuid' => (string) Str::uuid(), 'contrato_id' => $contrato->id, 'tipo' => 'visita', 'motivo' => 'desarrumacao'])->assertSessionHasNoErrors();
        $demanda = $this->gaboardi->run(fn () => Demanda::sole());
        tenancy()->end();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->ana)->get(self::SUL.'/alertas')->assertOk()->assertSee('Nova solicitação de visita');
        $this->get(self::SUL."/demandas/{$demanda->id}")->assertOk()->assertViewHas('podeGerir', true)->assertSee('João da Silva');
        $this->patch(self::SUL."/demandas/{$demanda->id}", ['decisao' => 'aprovar', 'promotor_id' => $this->joao->id])->assertSessionHasNoErrors();
        $this->gaboardi->run(function () use ($demanda) {
            $this->assertSame('aprovada', $demanda->fresh()->status);
            $this->assertSame($this->joao->id, $demanda->fresh()->visita->roteiro->promotor_id);
            $this->assertTrue(Alerta::where('agencia_id', $this->localGaboardi->id)->where('usuario_id', $this->joao->id)->where('tipo', 'demanda_aprovada')->exists());
        });
        // A indústria vê o promotor só pelo primeiro nome.
        $this->app['auth']->forgetGuards();
        $this->actingAs($gerente)->get("http://gaboardi.test/demandas/{$demanda->id}")->assertOk()->assertSee('João')->assertDontSee('João da Silva');
    }

    public function test_promotor_entra_pela_agencia_e_executa_em_cada_industria(): void
    {
        $resposta = $this->postJson(self::SUL.'/api/v1/login', ['email' => 'joao@sul.test', 'password' => 'senha1234', 'dispositivo' => 'Teste'])->assertOk();
        $this->assertSame('sul', $resposta->json('usuario.empresa.codigo'));

        foreach ([$this->gaboardi, $this->acme] as $tenant) {
            $tenant->run(function () {
                $visita = Visita::sole();
                $local = Agencia::where('agencia_tenant_id', 'sul')->sole();
                $roteiro = Roteiro::create(['promotor_id' => $this->joao->id, 'agencia_id' => $local->id, 'data' => '2026-10-14', 'supervisor_id' => $this->ana->id]);
                $visita->update(['roteiro_id' => $roteiro->id]);
            });
        }
        tenancy()->end();

        Sanctum::actingAs($this->joao, ['promotor']);
        $roteiro = $this->getJson(self::SUL.'/api/v1/roteiro?data=2026-10-14')->assertOk()
            ->assertJsonCount(2, 'visitas')->assertJsonCount(2, 'industrias')
            ->assertJsonPath('supervisor.nome', 'Ana Supervisora')
            ->assertJsonPath('visitas.0.industria', 'acme')->assertJsonPath('visitas.0.pdv.nome', 'Mercado Acme')->assertJsonPath('visitas.0.ordem', 1)
            ->assertJsonPath('visitas.1.industria', 'gaboardi')->assertJsonPath('visitas.1.pdv.nome', 'Super Centro')->assertJsonPath('visitas.1.ordem', 2)
            ->assertJsonPath('industrias.1.industria', 'gaboardi');
        $id = $roteiro->json('visitas.1.id');
        $gps = ['lat' => -29.168, 'lng' => -51.179, 'gps_em' => '2026-10-14T09:00:00-03:00', 'aparelho_em' => '2026-10-14T09:00:00-03:00', 'gps_simulado' => false];

        $this->postJson(self::SUL."/api/v1/industrias/gaboardi/visitas/{$id}/check-in", $gps)->assertCreated()->assertJsonPath('status', 'em_andamento');
        $this->getJson(self::SUL."/api/v1/industrias/gaboardi/visitas/{$id}")->assertOk()->assertJsonPath('visita.status', 'em_andamento');
        $this->getJson(self::SUL."/api/v1/industrias/acme/visitas/{$id}/check-in")->assertStatus(405);
        $this->postJson(self::SUL.'/api/v1/industrias/acme/visitas/999/check-in', $gps)->assertNotFound();
        $this->postJson(self::SUL."/api/v1/industrias/naoexiste/visitas/{$id}/check-in", $gps)->assertNotFound();
        // Sem o código da indústria, a agência não tem visitas.
        $this->postJson(self::SUL."/api/v1/visitas/{$id}/check-in", $gps)->assertNotFound();

        $this->gaboardi->run(fn () => $this->assertSame('em_andamento', Visita::sole()->status->value));
        $this->acme->run(fn () => $this->assertSame('previsto', Visita::sole()->status->value));
    }
}
