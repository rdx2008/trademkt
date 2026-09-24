<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Agencia;
use Tests\TestCase;

class ApiAppTest extends TestCase
{
    public function test_promotor_faz_login_e_consulta_perfil(): void
    {
        $tenant = $this->criarCliente('xyz', 'agencia');
        $agencia = $tenant->run(fn () => Agencia::first()->id);
        $this->criarUsuario($tenant, Perfil::Promotor, ['agencia_id' => $agencia, 'tipo_vinculo' => 'freelancer']);

        $token = $this->postJson('http://xyz.test/api/v1/login', [
            'email' => 'promotor@xyz.test',
            'password' => 'senha1234',
            'dispositivo' => 'Moto G - teste',
        ])->assertOk()->json('token');

        $this->assertNotEmpty($token);

        $this->withToken($token)
            ->getJson('http://xyz.test/api/v1/me')
            ->assertOk()
            ->assertJsonPath('usuario.perfil', 'promotor')
            ->assertJsonPath('usuario.tipo_vinculo', 'freelancer')
            ->assertJsonPath('usuario.empresa.codigo', 'xyz');
    }

    public function test_perfil_de_painel_nao_entra_pelo_app(): void
    {
        $this->criarCliente('xyz', 'agencia');

        $this->postJson('http://xyz.test/api/v1/login', [
            'email' => 'admin@xyz.test',
            'password' => 'senha1234',
            'dispositivo' => 'teste',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_token_de_um_cliente_nao_vale_em_outro(): void
    {
        $tenantA = $this->criarCliente('xyz', 'agencia');
        $this->criarCliente('abc', 'agencia');
        $agencia = $tenantA->run(fn () => Agencia::first()->id);
        $this->criarUsuario($tenantA, Perfil::Promotor, ['agencia_id' => $agencia, 'tipo_vinculo' => 'clt']);

        $token = $this->postJson('http://xyz.test/api/v1/login', [
            'email' => 'promotor@xyz.test',
            'password' => 'senha1234',
            'dispositivo' => 'teste',
        ])->json('token');

        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('http://abc.test/api/v1/me')->assertUnauthorized();
    }

    public function test_logout_invalida_o_token(): void
    {
        $tenant = $this->criarCliente('xyz', 'agencia');
        $agencia = $tenant->run(fn () => Agencia::first()->id);
        $this->criarUsuario($tenant, Perfil::Promotor, ['agencia_id' => $agencia, 'tipo_vinculo' => 'clt']);

        $token = $this->postJson('http://xyz.test/api/v1/login', [
            'email' => 'promotor@xyz.test',
            'password' => 'senha1234',
            'dispositivo' => 'teste',
        ])->json('token');

        $this->withToken($token)->postJson('http://xyz.test/api/v1/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('http://xyz.test/api/v1/me')->assertUnauthorized();
    }
}
