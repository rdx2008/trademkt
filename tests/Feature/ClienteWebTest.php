<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\User;
use Tests\TestCase;

class ClienteWebTest extends TestCase
{
    public function test_login_e_painel_do_cliente(): void
    {
        $this->criarCliente('gaboardi');

        $this->get('http://gaboardi.test/painel')->assertRedirect('http://gaboardi.test/entrar');

        $this->post('http://gaboardi.test/entrar', [
            'email' => 'ADMIN@gaboardi.test',
            'password' => 'senha1234',
        ])->assertRedirect('http://gaboardi.test/painel');

        $this->get('http://gaboardi.test/painel')
            ->assertOk()
            ->assertSee('Admin gaboardi')
            ->assertSee('Admin da instalação');
    }

    public function test_usuario_de_um_cliente_nao_entra_em_outro(): void
    {
        $this->criarCliente('gaboardi');
        $this->criarCliente('outra', 'agencia');

        $this->post('http://outra.test/entrar', [
            'email' => 'admin@gaboardi.test',
            'password' => 'senha1234',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_usuario_inativo_nao_entra(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        $this->criarUsuario($tenant, Perfil::GerenteTrade, ['ativo' => false]);

        $this->post('http://gaboardi.test/entrar', [
            'email' => 'gerente_trade@gaboardi.test',
            'password' => 'senha1234',
        ])->assertSessionHasErrors('email');
    }

    public function test_admin_da_agencia_cadastra_supervisor_pela_tela_de_usuarios_e_promotor_pela_tela_propria(): void
    {
        $tenant = $this->criarCliente('xyz', 'agencia');
        [$agenciaA, $agenciaB] = $tenant->run(fn () => [
            Agencia::first()->id,
            Agencia::create(['nome' => 'Outra agência'])->id,
        ]);
        $adminAgencia = $this->criarUsuario($tenant, Perfil::AdminAgencia, ['agencia_id' => $agenciaA]);

        $this->actingAs($adminAgencia);

        // Tenta mandar para outra agência: o sistema força a própria.
        $this->post('http://xyz.test/usuarios', [
            'nome' => 'Supervisor 1',
            'email' => 's1@xyz.test',
            'perfil' => 'supervisor',
            'agencia_id' => $agenciaB,
            'password' => 'senha1234',
            'password_confirmation' => 'senha1234',
            'ativo' => '1',
        ])->assertRedirect('http://xyz.test/usuarios');

        $tenant->run(function () use ($agenciaA) {
            $this->assertSame($agenciaA, User::where('email', 's1@xyz.test')->first()->agencia_id);
        });

        // Promotor não entra pela tela de Usuários: tem tela própria (CPF, foto e convite).
        $this->post('http://xyz.test/usuarios', [
            'nome' => 'Promotor 1', 'email' => 'p1@xyz.test', 'perfil' => 'promotor', 'tipo_vinculo' => 'freelancer',
            'password' => 'senha1234', 'password_confirmation' => 'senha1234',
        ])->assertSessionHasErrors('perfil');

        // Não pode criar admin da instalação.
        $this->post('http://xyz.test/usuarios', [
            'nome' => 'Hacker',
            'email' => 'h@xyz.test',
            'perfil' => 'admin_instalacao',
            'password' => 'senha1234',
            'password_confirmation' => 'senha1234',
        ])->assertSessionHasErrors('perfil');
    }

    public function test_promotor_nao_acessa_cadastros(): void
    {
        $tenant = $this->criarCliente('xyz', 'agencia');
        $agencia = $tenant->run(fn () => Agencia::first()->id);
        $promotor = $this->criarUsuario($tenant, Perfil::Promotor, ['agencia_id' => $agencia, 'tipo_vinculo' => 'clt']);

        $this->actingAs($promotor)->get('http://xyz.test/usuarios')->assertForbidden();
        $this->actingAs($promotor)->get('http://xyz.test/agencias')->assertForbidden();
    }

    public function test_promotor_exige_cpf_e_vinculo_quando_admin_cadastra(): void
    {
        $tenant = $this->criarCliente('xyz', 'agencia');
        $admin = $tenant->run(fn () => User::first());

        $this->actingAs($admin)->post('http://xyz.test/promotores', [
            'nome' => 'Promotor',
            'email' => 'p@xyz.test',
        ])->assertSessionHasErrors(['cpf', 'tipo_vinculo'])->assertSessionDoesntHaveErrors(['agencia_id']);
    }
}
