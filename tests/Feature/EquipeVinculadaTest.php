<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\Alerta;
use App\Models\ContratoVisita;
use App\Models\Industria;
use App\Models\Pdv;
use App\Models\Roteiro;
use App\Models\Sku;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visita;
use App\Services\Operacao\AlertasOperacao;
use App\Services\Operacao\Equipe;
use App\Services\Operacao\Indicadores;
use App\Services\Roteiros\Atribuicao;
use App\Services\Roteiros\GeradorVisitas;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A indústria não tem promotores: a equipe mora no banco da agência vinculada e a operação
 * (roteiros, visitas, demandas, alertas) referencia essas pessoas pelo id, com a agência ao lado.
 */
class EquipeVinculadaTest extends TestCase
{
    private const URL = 'http://gaboardi.test';

    private Tenant $industria;

    private Tenant $agencia;

    private Agencia $local;

    private Industria $marca;

    private Pdv $pdv;

    private ContratoVisita $contrato;

    private User $joao;

    private User $supervisora;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00'); // quarta
        $this->industria = $this->criarCliente('gaboardi');
        $this->agencia = $this->criarCliente('sul', 'agencia');
        $this->local = $this->vincular($this->industria, $this->agencia);

        $agenciaPropria = $this->agencia->run(fn () => Agencia::first()->id);
        $this->joao = $this->criarUsuario($this->agencia, Perfil::Promotor, ['nome' => 'João da Silva', 'email' => 'joao@sul.test', 'agencia_id' => $agenciaPropria, 'tipo_vinculo' => 'clt']);
        $this->supervisora = $this->criarUsuario($this->agencia, Perfil::Supervisor, ['nome' => 'Ana Supervisora', 'email' => 'ana@sul.test', 'agencia_id' => $agenciaPropria]);

        [$this->marca, $this->pdv, $this->contrato] = $this->industria->run(fn () => [
            $marca = Industria::first(),
            $pdv = Pdv::create(['cnpj' => self::cnpj(1), 'nome' => 'Super Centro', 'cidade' => 'Caxias do Sul', 'uf' => 'RS', 'lat' => -29.168, 'lng' => -51.179]),
            ContratoVisita::create(['pdv_id' => $pdv->id, 'industria_id' => $marca->id, 'agencia_id' => $this->local->id, 'frequencia_semana' => 1, 'dias' => [3], 'inicio' => '2026-10-01']),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function adminIndustria(): User
    {
        return $this->industria->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
    }

    public function test_a_equipe_da_agencia_e_lida_do_banco_dela_sem_copiar_nada_para_a_industria(): void
    {
        $this->industria->run(function () {
            $equipe = app(Equipe::class);
            $this->assertSame(['João da Silva'], $equipe->promotores($this->local)->pluck('nome')->all());
            $this->assertSame(['Admin sul', 'Ana Supervisora'], $equipe->gestores($this->local)->pluck('nome')->sort()->values()->all());
            $this->assertSame('João da Silva', $equipe->nome($this->local->id, $this->joao->id));
            $this->assertNull($equipe->promotorAtivo($this->local, $this->supervisora->id), 'supervisora não é promotora');
            $this->assertSame(0, User::whereIn('perfil', ['promotor', 'supervisor', 'admin_agencia'])->count(), 'nada da agência é gravado na indústria');
            $this->assertSame('gaboardi', tenant('id'), 'ler a equipe não troca a instalação em uso');
        });
    }

    public function test_industria_nao_cadastra_promotor_supervisor_nem_admin_de_agencia(): void
    {
        $admin = $this->adminIndustria();
        $this->actingAs($admin)->get(self::URL.'/usuarios/create')->assertOk()
            ->assertSee('Gerente de trade')->assertSee('Gerente do supermercado')
            ->assertDontSee('>Promotor<', false)->assertDontSee('Supervisor')->assertDontSee('Admin da agência')
            ->assertDontSee('Vínculo do promotor');
        foreach (['promotor', 'supervisor', 'admin_agencia'] as $perfil) {
            $this->post(self::URL.'/usuarios', ['nome' => 'Indevido', 'email' => "$perfil@x.test", 'perfil' => $perfil, 'agencia_id' => $this->local->id, 'tipo_vinculo' => 'clt', 'password' => 'senha1234', 'password_confirmation' => 'senha1234'])
                ->assertSessionHasErrors('perfil');
        }
        $this->post(self::URL.'/usuarios', ['nome' => 'Trade', 'email' => 'trade@x.test', 'perfil' => 'gerente_trade', 'industria_id' => $this->marca->id, 'password' => 'senha1234', 'password_confirmation' => 'senha1234', 'ativo' => '1'])
            ->assertSessionHasNoErrors();
        $this->get(self::URL.'/usuarios')->assertOk()->assertDontSee('>Promotor<', false);

        // Na agência, o admin cadastra supervisores sem escolher agência (todos são da própria); promotores
        // têm tela própria.
        $adminAgencia = $this->agencia->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
        $this->actingAs($adminAgencia)->get('http://sul.test/usuarios/create')->assertOk()
            ->assertSee('>Supervisor<', false)->assertDontSee('>Promotor<', false)->assertDontSee('Gerente de trade')->assertDontSee('name="agencia_id"', false);
        $this->post('http://sul.test/usuarios', ['nome' => 'Novo Supervisor', 'email' => 'novo@sul.test', 'perfil' => 'supervisor', 'password' => 'senha1234', 'password_confirmation' => 'senha1234', 'ativo' => '1'])
            ->assertSessionHasNoErrors();
        $this->agencia->run(fn () => $this->assertNotNull(User::where('email', 'novo@sul.test')->sole()->agencia_id));
        $this->get('http://sul.test/promotores/novo')->assertOk()->assertSee('Cadastrar e gerar convite');
    }

    public function test_roteiro_guarda_a_agencia_e_a_industria_so_ve_o_primeiro_nome_do_promotor(): void
    {
        $this->industria->run(function () {
            app(GeradorVisitas::class)->gerar(Carbon::parse('2026-10-12'), Carbon::parse('2026-10-18'));
            $visita = Visita::sole();
            app(Atribuicao::class)->mover($visita, $this->joao->id, $visita->data, $this->supervisora->id);
            $roteiro = Roteiro::sole();
            $this->assertSame($this->local->id, $roteiro->agencia_id);
            $this->assertSame($this->joao->id, $roteiro->promotor_id);
            $this->assertSame($this->supervisora->id, $roteiro->supervisor_id);
            $this->assertSame('João', $visita->fresh()->nomePromotor(), 'a indústria vê só o primeiro nome');
            $this->assertSame('Ana Supervisora', $roteiro->supervisor()->nome);
        });

        $admin = $this->adminIndustria();
        $this->actingAs($admin)->get(self::URL.'/roteiros?semana=2026-10-12')->assertOk()
            ->assertSee('João')->assertDontSee('João da Silva')->assertSee('Super Centro')
            ->assertViewHas('podeEditar', false);
        $visita = $this->industria->run(fn () => Visita::sole());
        $this->get(self::URL."/visitas/{$visita->id}")->assertOk()->assertSee('João')->assertDontSee('João da Silva')->assertDontSee('joao@sul.test');
        $this->get(self::URL.'/painel?data=2026-10-14')->assertOk()->assertSee('João')->assertDontSee('João da Silva');

        // Na semana seguinte, a visita nova repete o promotor da anterior, mesmo sendo de outro banco.
        $this->industria->run(function () {
            app(GeradorVisitas::class)->gerar(Carbon::parse('2026-10-19'), Carbon::parse('2026-10-25'));
            $this->assertSame($this->joao->id, Visita::whereDate('data', '2026-10-21')->sole()->roteiro->promotor_id);
        });
    }

    public function test_atribuir_exige_promotor_ativo_da_agencia_da_visita(): void
    {
        $outra = $this->criarCliente('norte', 'agencia');
        $outraLocal = $this->vincular($this->industria, $outra);
        $agenciaNorte = $outra->run(fn () => Agencia::first()->id);
        $this->criarUsuario($outra, Perfil::Supervisor, ['agencia_id' => $agenciaNorte]);
        $promotorDaOutra = $this->criarUsuario($outra, Perfil::Promotor, ['agencia_id' => $agenciaNorte, 'tipo_vinculo' => 'clt']);

        $this->industria->run(function () use ($promotorDaOutra, $outraLocal) {
            app(GeradorVisitas::class)->gerar(Carbon::parse('2026-10-12'), Carbon::parse('2026-10-18'));
            $visita = Visita::sole();
            // Ids coincidem entre bancos: o id é lido dentro da agência da visita (na Sul, é a supervisora).
            $this->assertSame($this->supervisora->id, $promotorDaOutra->id);
            try {
                app(Atribuicao::class)->mover($visita, $promotorDaOutra->id, $visita->data);
                $this->fail('promotor de outra agência não pode ser atribuído');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('promotor_id', $e->errors());
            }
            // Visita da outra agência: o João não serve.
            $visitaDaOutra = Visita::create(['data' => '2026-10-15', 'pdv_id' => $this->pdv->id, 'industria_id' => $this->marca->id, 'agencia_id' => $outraLocal->id, 'origem' => 'demanda']);
            app(Atribuicao::class)->mover($visitaDaOutra, $promotorDaOutra->id, $visitaDaOutra->data);
            app(Atribuicao::class)->mover($visita, $this->joao->id, $visita->data);
            // Roteiros de agências diferentes convivem, cada um com a sua agência.
            $this->assertSame(2, Roteiro::count());
            $this->assertSame([$this->local->id, $outraLocal->id], Roteiro::orderBy('agencia_id')->pluck('agencia_id')->sort()->values()->all());
            $this->assertSame(1, Roteiro::doPromotor($this->local->id, $this->joao->id, '2026-10-14')->count());
        });

        $this->agencia->run(fn () => $this->joao->update(['ativo' => false]));
        $this->industria->run(function () {
            Equipe::esquecer();
            $visita = Visita::where('agencia_id', $this->local->id)->sole();
            $visita->update(['roteiro_id' => null]);
            $this->expectException(ValidationException::class);
            app(Atribuicao::class)->mover($visita, $this->joao->id, $visita->data);
        });
    }

    public function test_alertas_chegam_a_equipe_da_agencia_com_a_agencia_marcada(): void
    {
        $trade = $this->criarUsuario($this->industria, Perfil::GerenteTrade, ['industria_id' => $this->marca->id]);
        $this->industria->run(function () use ($trade) {
            app(GeradorVisitas::class)->gerar(Carbon::parse('2026-10-12'), Carbon::parse('2026-10-18'));
            $visita = Visita::sole();
            app(Atribuicao::class)->mover($visita, $this->joao->id, $visita->data, $this->supervisora->id);
            app(AlertasOperacao::class)->emitir('ruptura', $visita->fresh(), 'Ruptura', 'Teste');

            $alertas = Alerta::get()->map(fn ($a) => [$a->agencia_id, $a->usuario_id])->sortBy(fn ($a) => $a[0].'-'.$a[1])->values()->all();
            $adminAgenciaId = $this->agencia->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->value('id'));
            $esperado = collect([
                [null, 1], [null, $trade->id],
                [$this->local->id, $adminAgenciaId], [$this->local->id, $this->supervisora->id], [$this->local->id, $this->joao->id],
            ])->sortBy(fn ($a) => $a[0].'-'.$a[1])->values()->all();
            $this->assertSame($esperado, $alertas);
            // Emitir de novo não duplica.
            app(AlertasOperacao::class)->emitir('ruptura', $visita->fresh(), 'Ruptura', 'Teste');
            $this->assertSame(count($esperado), Alerta::count());
        });

        // O admin da indústria vê só o alerta dele (ids iguais em bancos diferentes não se misturam).
        $this->actingAs($this->adminIndustria())->get(self::URL.'/alertas')->assertOk()->assertSee('Ruptura')->assertViewHas('naoLidas', 1);
    }

    public function test_indicadores_e_bi_mostram_o_promotor_da_agencia_e_filtram_por_ele(): void
    {
        $visita = $this->industria->run(function () {
            app(GeradorVisitas::class)->gerar(Carbon::parse('2026-10-12'), Carbon::parse('2026-10-18'));
            $visita = Visita::sole();
            app(Atribuicao::class)->mover($visita, $this->joao->id, $visita->data, $this->supervisora->id);
            $sku = Sku::create(['industria_id' => $this->marca->id, 'codigo' => 'A1', 'descricao' => 'Produto']);
            $visita->forceFill(['status' => 'realizada', 'tipo_conclusao' => 'normal', 'concluida_em' => '2026-10-14 09:30:00', 'duracao_segundos' => 600])->save();
            $visita->itens()->create(['sku_id' => $sku->id, 'situacao_estoque' => 'tem_estoque']);

            return $visita->fresh();
        });
        $admin = $this->adminIndustria();
        $filtros = ['inicio' => '2026-10-01', 'fim' => '2026-10-31'];
        $this->industria->run(function () use ($admin, $filtros) {
            $i = app(Indicadores::class);
            $opcoes = $i->opcoes($admin);
            $this->assertSame([$this->local->id.':'.$this->joao->id => 'João da Silva'], $opcoes['promotor_id']->all());
            $this->assertSame([$this->local->id.':'.$this->supervisora->id => 'Ana Supervisora'], $opcoes['supervisor_id']->all());
            $this->assertSame('João da Silva', $i->producao($admin, $filtros)->first()->promotor);
            $this->assertSame('João da Silva', $i->tempos($admin, $filtros)->first()->promotor);
            $this->assertSame(1, $i->visitas($admin, $filtros + ['promotor_id' => $this->local->id.':'.$this->joao->id])->count());
            $this->assertSame(0, $i->visitas($admin, $filtros + ['promotor_id' => '99:'.$this->joao->id])->count());
        });

        $this->actingAs($admin)->get(self::URL.'/indicadores?inicio=2026-10-01&fim=2026-10-31&promotor_id='.$this->local->id.':'.$this->joao->id)
            ->assertOk()->assertSee('João da Silva')->assertSee('Super Centro');
        $this->get(self::URL.'/indicadores/excel?inicio=2026-10-01&fim=2026-10-31')->assertOk();
        $this->get(self::URL.'/bi?inicio=2026-10-01&fim=2026-10-31')->assertOk();
    }
}
