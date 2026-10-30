<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\ContratoVisita;
use App\Models\Industria;
use App\Models\Pdv;
use App\Models\Roteiro;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visita;
use Tests\TestCase;

class VisibilidadeIndustriaTest extends TestCase
{
    private Tenant $tenant;

    private Industria $marca;

    private Agencia $prestadora;

    private Agencia $outraAgencia;

    private User $gerente;

    private User $promotor;

    private ContratoVisita $contrato;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->criarCliente('xyz', 'agencia');
        [$this->marca, $this->prestadora, $this->outraAgencia, $this->contrato] = $this->tenant->run(function () {
            $marca = Industria::create(['nome' => 'Marca acompanhada']);
            $outraMarca = Industria::create(['nome' => 'Marca reservada']);
            $prestadora = Agencia::first();
            $outra = Agencia::create(['nome' => 'Agência sem vínculo']);
            $pdv = Pdv::create(['cnpj' => self::cnpj(1), 'nome' => 'Loja acompanhada']);
            $dados = ['pdv_id' => $pdv->id, 'frequencia_semana' => 1, 'dias' => [1], 'inicio' => '2026-01-01'];
            $contrato = ContratoVisita::create($dados + ['industria_id' => $marca->id, 'agencia_id' => $prestadora->id]);
            ContratoVisita::create($dados + ['industria_id' => $outraMarca->id, 'agencia_id' => $outra->id]);

            return [$marca, $prestadora, $outra, $contrato];
        });
        $this->gerente = $this->criarUsuario($this->tenant, Perfil::GerenteTrade, ['industria_id' => $this->marca->id]);
        $this->promotor = $this->criarUsuario($this->tenant, Perfil::Promotor, [
            'nome' => 'Promotor da prestadora', 'agencia_id' => $this->prestadora->id, 'tipo_vinculo' => 'clt',
        ]);
    }

    public function test_gerente_ve_a_equipe_das_prestadoras_sem_usuarios_de_outras_operacoes(): void
    {
        $this->criarUsuario($this->tenant, Perfil::AdminAgencia, ['nome' => 'Admin da prestadora', 'agencia_id' => $this->prestadora->id]);
        $this->criarUsuario($this->tenant, Perfil::Supervisor, ['nome' => 'Supervisor da prestadora', 'agencia_id' => $this->prestadora->id]);
        $this->criarUsuario($this->tenant, Perfil::Representante, ['nome' => 'Representante da marca', 'industria_id' => $this->marca->id]);
        $this->criarUsuario($this->tenant, Perfil::Promotor, [
            'nome' => 'Promotor reservado', 'email' => 'reservado@xyz.test', 'agencia_id' => $this->outraAgencia->id,
        ]);
        $this->criarUsuario($this->tenant, Perfil::Supervisor, [
            'nome' => 'Supervisor reservado', 'email' => 'supervisor-reservado@xyz.test', 'agencia_id' => $this->outraAgencia->id,
        ]);

        $this->actingAs($this->gerente)->get('http://xyz.test/usuarios')
            ->assertOk()
            ->assertSee(['Admin da prestadora', 'Supervisor da prestadora', 'Promotor da prestadora', 'Representante da marca'])
            ->assertDontSee('Promotor reservado')
            ->assertDontSee('Supervisor reservado');

        // Busca e filtro não podem ampliar o acesso.
        $this->get('http://xyz.test/usuarios?busca=reservado&perfil=promotor')
            ->assertOk()->assertDontSee('Promotor reservado');

        $this->tenant->run(function () {
            $this->assertNull($this->promotor->fresh()->industria_id);
            $this->assertSame($this->prestadora->id, $this->promotor->fresh()->agencia_id);
        });
    }

    public function test_consultar_equipe_nao_permite_a_industria_editar_ou_criar_promotores(): void
    {
        $this->actingAs($this->gerente)->get("http://xyz.test/usuarios/{$this->promotor->id}/edit")->assertForbidden();
        $this->put("http://xyz.test/usuarios/{$this->promotor->id}", ['nome' => 'Alterado'])->assertForbidden();
        $this->post('http://xyz.test/usuarios', [
            'nome' => 'Promotor indevido', 'email' => 'indevido@xyz.test', 'perfil' => 'promotor',
            'agencia_id' => $this->prestadora->id, 'tipo_vinculo' => 'clt',
            'password' => 'senha1234', 'password_confirmation' => 'senha1234', 'ativo' => '1',
        ])->assertSessionHasErrors('perfil');

        $this->tenant->run(fn () => $this->assertFalse(User::where('email', 'indevido@xyz.test')->exists()));
    }

    public function test_filtros_da_industria_so_oferecem_suas_prestadoras_inclusive_no_historico(): void
    {
        $this->tenant->run(fn () => $this->contrato->update(['ativo' => false, 'fim' => '2026-02-01']));

        foreach ([Perfil::GerenteTrade, Perfil::Representante] as $perfil) {
            $usuario = $perfil === Perfil::GerenteTrade ? $this->gerente
                : $this->criarUsuario($this->tenant, $perfil, ['industria_id' => $this->marca->id]);
            $this->actingAs($usuario);
            foreach (['contratos', 'roteiros'] as $pagina) {
                $this->get('http://xyz.test/'.$pagina)->assertOk()
                    ->assertViewHas('agencias', fn ($agencias) => $agencias->keys()->all() === [$this->prestadora->id]);
            }
            $this->get('http://xyz.test/roteiros?agencia_id='.$this->outraAgencia->id)->assertForbidden();
        }

        $this->actingAs($this->gerente)->get('http://xyz.test/usuarios')->assertOk()->assertSee('Promotor da prestadora');
    }

    public function test_industria_acompanha_todas_as_prestadoras_mas_so_visitas_da_sua_marca(): void
    {
        $segunda = $this->tenant->run(function () {
            $agencia = Agencia::create(['nome' => 'Segunda prestadora']);
            $pdv = Pdv::create(['cnpj' => self::cnpj(2), 'nome' => 'Segunda loja acompanhada']);
            ContratoVisita::create([
                'pdv_id' => $pdv->id, 'industria_id' => $this->marca->id, 'agencia_id' => $agencia->id,
                'frequencia_semana' => 1, 'dias' => [1], 'inicio' => '2026-01-01',
            ]);
            foreach (ContratoVisita::all() as $contrato) {
                $promotor = $contrato->agencia_id === $this->prestadora->id ? $this->promotor
                    : User::create([
                        'nome' => 'Equipe '.$contrato->agencia_id, 'email' => 'equipe'.$contrato->agencia_id.'@xyz.test',
                        'password' => 'senha1234', 'perfil' => Perfil::Promotor, 'agencia_id' => $contrato->agencia_id,
                    ]);
                $roteiro = Roteiro::create(['promotor_id' => $promotor->id, 'data' => today()]);
                Visita::create([
                    'roteiro_id' => $roteiro->id, 'data' => today(), 'pdv_id' => $contrato->pdv_id,
                    'industria_id' => $contrato->industria_id, 'agencia_id' => $contrato->agencia_id, 'origem' => 'contrato',
                ]);
            }

            // A mesma agência e o mesmo promotor também atendem outra marca.
            // Compartilhar a equipe não libera as visitas dessa outra indústria.
            Visita::create([
                'roteiro_id' => Roteiro::where('promotor_id', $this->promotor->id)->sole()->id,
                'data' => today(), 'pdv_id' => $this->contrato->pdv_id,
                'industria_id' => Industria::where('nome', 'Marca reservada')->sole()->id,
                'agencia_id' => $this->prestadora->id, 'origem' => 'demanda',
            ]);

            return $agencia;
        });

        $this->actingAs($this->gerente)->get('http://xyz.test/roteiros')->assertOk()
            ->assertSee(['Todas as agências prestadoras', 'Segunda prestadora', 'Promotor da prestadora', 'Segunda loja acompanhada'])
            ->assertDontSee('Marca reservada')->assertDontSee('Agência sem vínculo')
            ->assertViewHas('podeEditar', false)
            ->assertViewHas('promotores', fn ($promotores) => $promotores->count() === 2);

        $this->get('http://xyz.test/roteiros?agencia_id='.$segunda->id)->assertOk()
            ->assertSee('Segunda loja acompanhada')->assertDontSee('Promotor da prestadora')
            ->assertViewHas('promotores', fn ($promotores) => $promotores->count() === 1);
    }

    public function test_gerente_sem_industria_nao_ve_todos_os_usuarios_sem_vinculo(): void
    {
        $semIndustria = $this->criarUsuario($this->tenant, Perfil::GerenteTrade, ['email' => 'sem-industria@xyz.test']);
        $this->actingAs($semIndustria)->get('http://xyz.test/usuarios')->assertOk()
            ->assertDontSee('Promotor da prestadora')
            ->assertViewHas('usuarios', fn ($usuarios) => $usuarios->pluck('id')->all() === [$semIndustria->id]);
        $this->get('http://xyz.test/contratos')->assertOk()->assertViewHas('agencias', fn ($agencias) => $agencias->isEmpty());
    }
}
