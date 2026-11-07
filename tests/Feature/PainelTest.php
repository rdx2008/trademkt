<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Admin;
use App\Models\Agencia;
use App\Models\ContratoVisita;
use App\Models\Industria;
use App\Models\Pdv;
use App\Models\Roteiro;
use App\Models\Sku;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visita;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PainelTest extends TestCase
{
    private Tenant $tenant;

    private Agencia $agencia;

    private Industria $marca;

    private User $promotor;

    private array $pdvs;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');
        $this->tenant = $this->criarCliente('xyz', 'agencia');
        [$this->agencia, $outraAgencia, $this->marca, $outraMarca, $this->pdvs] = $this->tenant->run(function () {
            $agencia = Agencia::first();
            $agencia->update(['nome' => 'Agência Horizonte']);
            $outra = Agencia::create(['nome' => 'Agência Reservada']);
            $marca = Industria::create(['nome' => 'Marca Aurora']);
            $marcaB = Industria::create(['nome' => 'Marca Reservada']);
            Sku::create(['industria_id' => $marca->id, 'codigo' => 'A1', 'descricao' => 'Produto Aurora']);
            Sku::create(['industria_id' => $marcaB->id, 'codigo' => 'B1', 'descricao' => 'Produto B']);
            $pdvs = [];
            foreach (['Super Centro', 'Loja Reservada', 'Mercado Sul', 'Super Norte'] as $i => $nome) {
                $pdvs[] = Pdv::create(['cnpj' => self::cnpj($i + 1), 'nome' => $nome, 'cidade' => 'Caxias do Sul', 'uf' => 'RS', 'logradouro' => 'Rua das Flores', 'numero' => (string) (100 + $i), 'lat' => -29.168, 'lng' => -51.179]);
            }

            return [$agencia, $outra, $marca, $marcaB, $pdvs];
        });
        $this->promotor = $this->criarUsuario($this->tenant, Perfil::Promotor, ['nome' => 'João Silva', 'agencia_id' => $this->agencia->id, 'tipo_vinculo' => 'clt']);
        $outro = $this->criarUsuario($this->tenant, Perfil::Promotor, ['nome' => 'Promotor Reservado', 'email' => 'outro@xyz.test', 'agencia_id' => $outraAgencia->id]);
        $this->tenant->run(function () use ($outraAgencia, $outraMarca, $outro) {
            $roteiroA = Roteiro::create(['promotor_id' => $this->promotor->id, 'data' => '2026-10-31']);
            $roteiroB = Roteiro::create(['promotor_id' => $outro->id, 'data' => '2026-10-31']);
            foreach ([[$this->marca, $this->agencia, $roteiroA, 'em_andamento'], [$outraMarca, $outraAgencia, $roteiroB, 'realizada'], [$outraMarca, $this->agencia, $roteiroA, 'previsto'], [$this->marca, $outraAgencia, $roteiroB, 'em_atencao']] as $i => [$marca, $agencia, $roteiro, $status]) {
                $contrato = ContratoVisita::create(['pdv_id' => $this->pdvs[$i]->id, 'industria_id' => $marca->id, 'agencia_id' => $agencia->id, 'frequencia_semana' => 1, 'dias' => [6], 'inicio' => '2026-10-01']);
                $visita = Visita::create(['roteiro_id' => $roteiro->id, 'data' => '2026-10-31', 'pdv_id' => $this->pdvs[$i]->id, 'industria_id' => $marca->id, 'agencia_id' => $agencia->id, 'contrato_id' => $contrato->id, 'origem' => 'contrato', 'status' => $status]);
                if ($status === 'em_andamento') {
                    $visita->check_in = ['gps_em' => '2026-10-31T09:35:00-03:00'];
                    $visita->save();
                }
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function perfis(): array
    {
        return [
            'admin agência' => [Perfil::AdminInstalacao, 'agencia', 'Visão da operação', 4],
            'admin indústria' => [Perfil::AdminInstalacao, 'industria', 'Visão da operação', 4],
            'admin da agência' => [Perfil::AdminAgencia, 'agencia', 'Gestão da agência', 2],
            'gerente de trade' => [Perfil::GerenteTrade, 'industria', 'Resultados da indústria', 2],
            'supervisor' => [Perfil::Supervisor, 'agencia', 'Central do supervisor', 2],
            'representante' => [Perfil::Representante, 'industria', 'Minha carteira', 1],
            'promotor' => [Perfil::Promotor, 'agencia', 'Minha agenda', 2],
            'supermercado' => [Perfil::GerentePdv, 'agencia', 'Minhas lojas', 1],
        ];
    }

    private function usuario(Perfil $perfil): User
    {
        if ($perfil === Perfil::Promotor) {
            return $this->promotor;
        }
        $usuario = $this->criarUsuario($this->tenant, $perfil, ['nome' => $perfil->label(), 'agencia_id' => $perfil->exigeAgencia() ? $this->agencia->id : null, 'industria_id' => $perfil->exigeIndustria() ? $this->marca->id : null]);
        $this->tenant->run(function () use ($usuario, $perfil) {
            if ($perfil === Perfil::Representante) {
                $usuario->carteiraPdvs()->attach($this->pdvs[0]);
            }
            if ($perfil === Perfil::GerentePdv) {
                $usuario->pdvsGerenciados()->attach($this->pdvs[0]);
            }
        });

        return $usuario;
    }

    #[DataProvider('perfis')]
    public function test_painel_de_cada_nivel_respeita_visibilidade(Perfil $perfil, string $tipo, string $titulo, int $total): void
    {
        $this->tenant->update(['tipo' => $tipo]);
        $usuario = $this->usuario($perfil);
        $resposta = $this->actingAs($usuario)->get('http://xyz.test/painel')
            ->assertOk()->assertSee($titulo)->assertSee('Super Centro')
            ->assertViewHas('resumo', fn ($r) => $r['total'] === $total && $r['visitas']->total() === $total && $r['semana']->sum('total') === $total);

        if ($perfil !== Perfil::AdminInstalacao) {
            $resposta->assertDontSee('Loja Reservada');
        }
        if ($perfil === Perfil::GerenteTrade || $perfil === Perfil::Representante) {
            $resposta->assertDontSee('Marca Reservada')->assertDontSee('Distribuir visitas')->assertDontSee('Novo contrato');
        }
        if ($perfil === Perfil::AdminAgencia || $perfil === Perfil::Supervisor || $perfil === Perfil::Promotor || $perfil === Perfil::GerentePdv) {
            $resposta->assertDontSee('Agência Reservada')->assertDontSee('Promotor Reservado');
        }
        if ($perfil === Perfil::Promotor || $perfil === Perfil::GerentePdv) {
            $resposta->assertDontSee('Gerenciar a equipe')->assertDontSee('Cadastrar um PDV');
        }
        $this->salvarPrevia($perfil->value.'-'.$tipo, $resposta->getContent());
    }

    public function test_filtro_de_status_nao_altera_resumo_e_nao_expoe_outra_agencia(): void
    {
        $this->actingAs($this->usuario(Perfil::Supervisor))->get('http://xyz.test/painel?status=realizada')
            ->assertOk()->assertSee('Nenhuma visita nesta situação')->assertDontSee('Loja Reservada')
            ->assertViewHas('resumo', fn ($r) => $r['total'] === 2 && $r['visitas']->total() === 0);
    }

    public function test_consulta_dia_vazio_e_rejeita_filtros_invalidos(): void
    {
        $this->actingAs($this->promotor)->get('http://xyz.test/painel?data=2026-11-02')->assertOk()
            ->assertSee('Nenhuma visita nesta data')->assertViewHas('resumo', fn ($r) => $r['total'] === 0);
        $this->getJson('http://xyz.test/painel?data=invalida')->assertUnprocessable();
        $this->getJson('http://xyz.test/painel?status=invalido')->assertUnprocessable();
    }

    public function test_representante_e_gerente_sem_vinculo_nao_veem_dados_da_instalacao(): void
    {
        foreach ([Perfil::Representante, Perfil::GerentePdv] as $perfil) {
            $usuario = $this->criarUsuario($this->tenant, $perfil, ['industria_id' => $perfil->exigeIndustria() ? $this->marca->id : null]);
            $this->actingAs($usuario)->get('http://xyz.test/painel')->assertOk()->assertDontSee('Super Centro')
                ->assertViewHas('resumo', fn ($r) => $r['total'] === 0);
        }
    }

    public function test_paginacao_preserva_data_e_situacao(): void
    {
        $this->tenant->run(function () {
            $modelo = Visita::first();
            for ($i = 0; $i < 14; $i++) {
                $copia = $modelo->replicate();
                $copia->contrato_id = null;
                $copia->save();
            }
        });
        $this->actingAs($this->promotor)->get('http://xyz.test/painel?data=2026-10-31&status=em_andamento&page=2')
            ->assertOk()->assertSee('Página 2 de 2')->assertSee('data=2026-10-31', false)->assertSee('status=em_andamento', false)
            ->assertViewHas('resumo', fn ($r) => $r['visitas']->count() === 3);
    }

    public function test_super_admin_tem_resumo_central_protegido(): void
    {
        $this->get('http://central.test/visao-geral')->assertRedirect('http://central.test/entrar');
        $admin = Admin::create(['nome' => 'Rodolfo', 'email' => 'admin@central.test', 'password' => 'senha1234']);
        $resposta = $this->actingAs($admin, 'admin')->get('http://central.test/visao-geral')
            ->assertOk()->assertSee('Visão geral da plataforma')->assertViewHas('total', 1)->assertViewHas('ativos', 1)
            ->assertDontSee('Marca Aurora');
        $this->salvarPrevia('super-admin', $resposta->getContent());
    }

    private function salvarPrevia(string $nome, string $html): void
    {
        if ($pasta = getenv('PAINEL_PREVIEW_DIR')) {
            if (! is_dir($pasta)) {
                mkdir($pasta, 0777, true);
            }
            file_put_contents($pasta.'/'.$nome.'.html', preg_replace('~https?://[^/]+/css/app\.css~', '/app.css', $html));
        }
    }
}
