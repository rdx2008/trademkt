<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marca\Aparencia;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CentralTest extends TestCase
{
    public function test_api_descobre_empresa_pelo_codigo(): void
    {
        $this->criarCliente('gaboardi');

        $this->getJson('http://central.test/api/v1/empresas/GABOARDI')
            ->assertOk()
            ->assertJson([
                'codigo' => 'gaboardi',
                'url' => 'http://gaboardi.test',
                'api' => 'http://gaboardi.test/api/v1',
            ]);
    }

    public function test_api_devolve_a_marca_do_cliente_para_o_app(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        $admin = $tenant->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->first());

        // Sem personalização: só o nome do cliente.
        $this->getJson('http://central.test/api/v1/empresas/gaboardi')
            ->assertOk()
            ->assertJsonPath('marca', ['nome' => 'Gaboardi', 'cor' => null, 'logo_url' => null, 'icone_url' => null]);

        $this->actingAs($admin)->put('http://gaboardi.test/configuracoes/aparencia', [
            'nome' => 'Gaboardi Trade',
            'cor' => '#0f766e',
            'logo_claro' => UploadedFile::fake()->image('claro.png', 600, 200),
            'icone' => UploadedFile::fake()->image('icone.png', 300, 300),
        ])->assertSessionHasNoErrors();
        tenancy()->end();
        Aparencia::esquecer();

        $marca = $this->getJson('http://central.test/api/v1/empresas/gaboardi')->assertOk()->json('marca');
        $this->assertSame('Gaboardi Trade', $marca['nome']);
        $this->assertSame('#0f766e', $marca['cor']);
        $this->assertStringStartsWith('http://gaboardi.test/marca/claro?v=', $marca['logo_url']);
        $this->assertStringStartsWith('http://gaboardi.test/marca/icone?v=', $marca['icone_url']);
        $this->get($marca['logo_url'])->assertOk();
        tenancy()->end();

        // Fornecedor desligou a marca própria: volta ao padrão, sem vazar as escolhas.
        $tenant->white_label = false;
        $tenant->save();
        Aparencia::esquecer();

        $this->getJson('http://central.test/api/v1/empresas/gaboardi')
            ->assertOk()
            ->assertJsonPath('marca', ['nome' => 'Gaboardi', 'cor' => null, 'logo_url' => null, 'icone_url' => null]);
    }

    public function test_api_nao_encontra_cliente_suspenso_ou_inexistente(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        $tenant->update(['ativo' => false]);

        $this->getJson('http://central.test/api/v1/empresas/gaboardi')->assertNotFound();
        $this->getJson('http://central.test/api/v1/empresas/naoexiste')->assertNotFound();
    }

    public function test_painel_central_exige_login_e_cria_cliente(): void
    {
        $this->get('http://central.test/clientes')->assertRedirect('http://central.test/entrar');

        $admin = Admin::create(['nome' => 'Rodolfo', 'email' => 'r@mastervps.test', 'password' => 'senha1234']);

        $this->actingAs($admin, 'admin')
            ->post('http://central.test/clientes', [
                'id' => 'gaboardi',
                'nome' => 'Gaboardi',
                'tipo' => 'industria',
                'dominios' => "trade.gaboardi.test\ngaboardi.test",
                'admin_nome' => 'Admin',
                'admin_email' => 'admin@gaboardi.test',
            ])
            ->assertRedirect('http://central.test/clientes')
            ->assertSessionHas('criado');

        $this->assertSame(2, Tenant::find('gaboardi')->domains()->count());
    }

    public function test_suspender_cliente_bloqueia_o_painel_dele(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        $admin = Admin::create(['nome' => 'Rodolfo', 'email' => 'r@mastervps.test', 'password' => 'senha1234']);

        $this->actingAs($admin, 'admin')
            ->patch('http://central.test/clientes/gaboardi/ativo')
            ->assertRedirect();

        $this->assertFalse($tenant->fresh()->ativo);
        $this->get('http://gaboardi.test/entrar')->assertForbidden();
    }

    public function test_dominio_central_nao_abre_rotas_de_cliente(): void
    {
        $this->get('http://central.test/painel')->assertNotFound();
    }

    public function test_dominio_de_cliente_nao_abre_painel_central(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        $usuario = $this->criarUsuario($tenant, Perfil::GerenteTrade);

        $this->get('http://gaboardi.test/clientes')->assertNotFound();
        $this->getJson('http://gaboardi.test/api/v1/empresas/gaboardi')->assertNotFound();
        $this->assertNotNull($usuario);
    }
}
