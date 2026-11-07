<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

class DominiosClienteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['trade.dominio_clientes' => 'trade.mastervps.online']);
        $admin = Admin::create(['nome' => 'Admin', 'email' => 'admin@central.test', 'password' => 'senha1234']);
        $this->actingAs($admin, 'admin');
    }

    public function test_cadastra_cliente_com_subdominio_sem_exigir_dominio_proprio(): void
    {
        $this->post('http://central.test/clientes', ['id' => 'gaboardi', 'nome' => 'Gaboardi', 'tipo' => 'industria', 'tipo_endereco' => 'subdominio', 'subdominio' => 'gaboardi', 'admin_nome' => 'Admin', 'admin_email' => 'admin@gaboardi.test'])
            ->assertRedirect('http://central.test/clientes')->assertSessionHasNoErrors();
        $cliente = Tenant::findOrFail('gaboardi');
        $this->assertSame('gaboardi.trade.mastervps.online', $cliente->dominioPrincipal());
    }

    public function test_adiciona_dominio_proprio_e_troca_principal_sem_mudar_banco_ou_usuario(): void
    {
        $cliente = $this->criarCliente('gaboardi', 'industria');
        $usuarioId = $cliente->run(fn () => User::first()->id);
        $this->post('http://central.test/clientes/gaboardi/dominios', ['tipo_endereco' => 'proprio', 'dominios' => 'trade.gaboardi.com.br'])->assertSessionHasNoErrors();
        $dominio = $cliente->domains()->where('domain', 'trade.gaboardi.com.br')->firstOrFail();
        $this->patch('http://central.test/clientes/gaboardi/dominios/'.$dominio->id.'/principal')->assertSessionHasNoErrors();
        $this->assertSame('trade.gaboardi.com.br', $cliente->fresh()->dominioPrincipal());
        $this->assertSame(2, $cliente->domains()->count());
        $this->assertSame($usuarioId, $cliente->run(fn () => User::first()->id));
        $this->assertFileExists(database_path('teste_cli_gaboardi'));
    }

    public function test_impede_repeticao_reservados_e_dominio_central(): void
    {
        $cliente = $this->criarCliente('gaboardi');
        foreach ([['tipo_endereco' => 'subdominio', 'subdominio' => 'admin'], ['tipo_endereco' => 'subdominio', 'subdominio' => 'nome.invalido'], ['tipo_endereco' => 'proprio', 'dominios' => 'central.test'], ['tipo_endereco' => 'proprio', 'dominios' => 'gaboardi.test']] as $dados) {
            $this->postJson('http://central.test/clientes/gaboardi/dominios', $dados)->assertUnprocessable();
        }
        $this->assertSame(1, $cliente->domains()->count());
    }

    public function test_nao_remove_ultimo_nem_altera_dominio_de_outro_cliente(): void
    {
        $cliente = $this->criarCliente('gaboardi');
        $outro = $this->criarCliente('outro');
        $this->deleteJson('http://central.test/clientes/gaboardi/dominios/'.$cliente->domains()->first()->id)->assertUnprocessable();
        $this->patchJson('http://central.test/clientes/gaboardi/dominios/'.$outro->domains()->first()->id.'/principal')->assertNotFound();
        $this->assertSame('gaboardi.test', $cliente->fresh()->dominioPrincipal());
    }

    public function test_remover_principal_escolhe_endereco_restante(): void
    {
        $cliente = $this->criarCliente('gaboardi');
        $antigo = $cliente->domains()->first();
        $cliente->domains()->create(['domain' => 'novo.gaboardi.test']);
        $this->delete('http://central.test/clientes/gaboardi/dominios/'.$antigo->id)->assertSessionHasNoErrors();
        $this->assertSame('novo.gaboardi.test', $cliente->fresh()->dominioPrincipal());
    }
}
