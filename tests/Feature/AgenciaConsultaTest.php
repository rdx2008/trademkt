<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\ContratoVisita;
use App\Models\Industria;
use App\Models\Pdv;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgenciaConsultaTest extends TestCase
{
    public static function tiposInstalacao(): array
    {
        return ['instalação de agência' => ['agencia'], 'instalação de indústria' => ['industria']];
    }

    private function cenario(string $tipo = 'agencia'): array
    {
        $tenant = $this->criarCliente('xyz', $tipo);
        [$prestadora, $outra, $marca, $contrato] = $tenant->run(function () {
            $prestadora = Agencia::first() ?? Agencia::create(['nome' => 'Prestadora exclusiva']);
            $outra = Agencia::create(['nome' => 'Agência reservada', 'cnpj' => 'reservado']);
            $marca = Industria::first() ?? Industria::create(['nome' => 'Marca acompanhada']);
            $outraMarca = Industria::create(['nome' => 'Marca reservada']);
            $pdv = Pdv::create(['nome' => 'Loja', 'cnpj' => self::cnpj(1)]);
            $dados = ['pdv_id' => $pdv->id, 'frequencia_semana' => 1, 'dias' => [1], 'inicio' => '2026-01-01'];
            $contrato = ContratoVisita::create($dados + ['industria_id' => $marca->id, 'agencia_id' => $prestadora->id]);
            ContratoVisita::create($dados + ['industria_id' => $outraMarca->id, 'agencia_id' => $outra->id]);

            return [$prestadora, $outra, $marca, $contrato];
        });
        $gerente = $this->criarUsuario($tenant, Perfil::GerenteTrade, ['industria_id' => $marca->id]);

        return compact('tenant', 'prestadora', 'outra', 'marca', 'contrato', 'gerente');
    }

    #[DataProvider('tiposInstalacao')]
    public function test_industria_consulta_apenas_suas_prestadoras_nos_dois_tipos_de_instalacao(string $tipo): void
    {
        $c = $this->cenario($tipo);
        foreach ([Perfil::AdminAgencia, Perfil::Supervisor, Perfil::Promotor] as $perfil) {
            $this->criarUsuario($c['tenant'], $perfil, ['agencia_id' => $c['prestadora']->id]);
        }
        $representante = $this->criarUsuario($c['tenant'], Perfil::Representante, ['industria_id' => $c['marca']->id]);

        foreach ([$c['gerente'], $representante] as $usuario) {
            $this->actingAs($usuario)->get('http://xyz.test/agencias')->assertOk()
                ->assertSee($c['prestadora']->nome)->assertDontSee('Agência reservada')
                ->assertDontSee('Nova agência')->assertDontSee('>Editar</a>', false)
                ->assertViewHas('podeCadastrar', false)
                ->assertViewHas('agencias', fn ($agencias) => $agencias->count() === 1
                    && $agencias->first()->id === $c['prestadora']->id
                    && $agencias->first()->usuarios_count === ($tipo === 'agencia' ? 4 : 3));
            $this->get('http://xyz.test/painel')->assertOk()->assertSee('http://xyz.test/agencias');
        }
    }

    public function test_prestadora_com_contrato_encerrado_permanece_disponivel_no_historico(): void
    {
        $c = $this->cenario();
        $c['tenant']->run(fn () => $c['contrato']->update(['fim' => '2026-02-01', 'ativo' => false]));

        $this->actingAs($c['gerente'])->get('http://xyz.test/agencias')->assertOk()
            ->assertSee($c['prestadora']->nome)->assertDontSee('Agência reservada');
    }

    public function test_agencia_e_supervisor_consultam_somente_a_propria_agencia_sem_poder_editar(): void
    {
        $c = $this->cenario();
        foreach ([Perfil::AdminAgencia, Perfil::Supervisor] as $perfil) {
            $usuario = $this->criarUsuario($c['tenant'], $perfil, ['agencia_id' => $c['prestadora']->id]);
            $this->actingAs($usuario)->get('http://xyz.test/agencias')->assertOk()
                ->assertSee($c['prestadora']->nome)->assertDontSee('Agência reservada');
            $this->get('http://xyz.test/agencias/create')->assertForbidden();
            $this->get('http://xyz.test/agencias/'.$c['prestadora']->id.'/edit')->assertForbidden();
            $this->post('http://xyz.test/agencias', ['nome' => 'Indevida'])->assertForbidden();
            $this->put('http://xyz.test/agencias/'.$c['prestadora']->id, ['nome' => 'Alterada'])->assertForbidden();
        }
    }

    public function test_industria_nao_pode_criar_ou_editar_agencias_nem_por_url_direta(): void
    {
        $c = $this->cenario();
        $representante = $this->criarUsuario($c['tenant'], Perfil::Representante, ['industria_id' => $c['marca']->id]);
        foreach ([$c['gerente'], $representante] as $usuario) {
            $this->actingAs($usuario)->get('http://xyz.test/agencias/create')->assertForbidden();
            $this->post('http://xyz.test/agencias', ['nome' => 'Indevida'])->assertForbidden();
            foreach ([$c['prestadora'], $c['outra']] as $agencia) {
                $this->get("http://xyz.test/agencias/{$agencia->id}/edit")->assertForbidden();
                $this->put("http://xyz.test/agencias/{$agencia->id}", ['nome' => 'Alterada'])->assertForbidden();
            }
        }
        $c['tenant']->run(fn () => $this->assertSame(2, Agencia::count()));
    }

    public function test_sem_vinculo_nao_lista_agencias_e_perfis_sem_acesso_sao_bloqueados(): void
    {
        $c = $this->cenario();
        foreach ([Perfil::GerenteTrade, Perfil::Representante, Perfil::AdminAgencia, Perfil::Supervisor] as $perfil) {
            $usuario = $this->criarUsuario($c['tenant'], $perfil, ['email' => $perfil->value.'-sem-vinculo@xyz.test']);
            $this->actingAs($usuario)->get('http://xyz.test/agencias')->assertOk()
                ->assertViewHas('agencias', fn ($agencias) => $agencias->isEmpty())
                ->assertSee('Nenhuma agência disponível para consulta.');
        }
        foreach ([Perfil::Promotor, Perfil::GerentePdv] as $perfil) {
            $this->actingAs($this->criarUsuario($c['tenant'], $perfil))
                ->get('http://xyz.test/agencias')->assertForbidden();
        }
    }

    public function test_admin_da_instalacao_continua_cadastrando_e_editando_agencias(): void
    {
        $c = $this->cenario();
        $admin = $c['tenant']->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
        $this->actingAs($admin)->get('http://xyz.test/agencias')->assertOk()
            ->assertSee([$c['prestadora']->nome, 'Agência reservada', 'Nova agência'])
            ->assertViewHas('podeCadastrar', true);
        $this->get('http://xyz.test/agencias/create')->assertOk();
        $this->post('http://xyz.test/agencias', ['nome' => 'Nova prestadora', 'ativo' => '1'])
            ->assertRedirect('http://xyz.test/agencias')->assertSessionHasNoErrors();
        $nova = $c['tenant']->run(fn () => Agencia::where('nome', 'Nova prestadora')->sole());
        $this->get("http://xyz.test/agencias/{$nova->id}/edit")->assertOk();
        $this->put("http://xyz.test/agencias/{$nova->id}", ['nome' => 'Prestadora atualizada', 'ativo' => '1'])
            ->assertRedirect('http://xyz.test/agencias')->assertSessionHasNoErrors();
        $c['tenant']->run(fn () => $this->assertSame('Prestadora atualizada', $nova->fresh()->nome));
    }
}
