<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\Industria;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProvisionamentoTest extends TestCase
{
    public function test_cria_cliente_com_banco_pasta_dominio_e_admin(): void
    {
        $tenant = $this->criarCliente('gaboardi', 'industria');

        $this->assertSame('gaboardi', $tenant->codigo);
        $this->assertSame('gaboardi.test', $tenant->dominioPrincipal());
        $this->assertFileExists(database_path('teste_cli_gaboardi'));
        $this->assertDirectoryExists(storage_path('clientes/gaboardi/app/fotos'));

        $tenant->run(function () {
            $admin = User::first();
            $this->assertSame(Perfil::AdminInstalacao, $admin->perfil);
            $this->assertSame('admin@gaboardi.test', $admin->email);
            // Instalação de indústria já nasce com a própria indústria.
            $this->assertSame(1, Industria::count());
            $this->assertSame(0, Agencia::count());
            $this->assertSame($admin->industria_id, Industria::first()->id);
        });
    }

    public function test_instalacao_de_agencia_nasce_com_a_agencia(): void
    {
        $tenant = $this->criarCliente('agenciaxyz', 'agencia');

        $tenant->run(function () {
            $this->assertSame(1, Agencia::count());
            $this->assertSame(0, Industria::count());
        });
    }

    public function test_recusa_dominio_repetido(): void
    {
        $this->criarCliente('gaboardi');

        $this->expectException(ValidationException::class);
        $this->criarCliente('outro', 'agencia', 'gaboardi.test');
    }

    public function test_recusa_dominio_central_e_identificador_invalido(): void
    {
        try {
            $this->criarCliente('Nome Invalido', 'agencia', 'central.test');
            $this->fail('Deveria recusar.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('id', $e->errors());
            $this->assertArrayHasKey('dominios.0', $e->errors());
        }

        $this->assertSame(0, Tenant::count());
    }

    public function test_comando_cliente_criar(): void
    {
        $this->artisan('cliente:criar', [
            'id' => 'gaboardi',
            '--nome' => 'Gaboardi',
            '--tipo' => 'industria',
            '--dominio' => ['trade.gaboardi.test'],
            '--codigo' => 'gab',
            '--admin-nome' => 'Rodolfo',
            '--admin-email' => 'rodolfo@gaboardi.test',
        ])->assertSuccessful();

        $tenant = Tenant::find('gaboardi');
        $this->assertNotNull($tenant);
        $this->assertSame('gab', $tenant->codigo);
        $this->assertSame('trade.gaboardi.test', $tenant->dominioPrincipal());
    }

    public function test_comando_cliente_dominio_adiciona_e_remove(): void
    {
        $this->criarCliente('gaboardi');

        $this->artisan('cliente:dominio', ['id' => 'gaboardi', 'dominio' => 'extra.gaboardi.test'])->assertSuccessful();
        $this->assertSame(2, Tenant::find('gaboardi')->domains()->count());

        $this->artisan('cliente:dominio', ['id' => 'gaboardi', 'dominio' => 'extra.gaboardi.test', '--remover' => true])->assertSuccessful();
        $this->assertSame(1, Tenant::find('gaboardi')->domains()->count());

        // Não deixa ficar sem domínio.
        $this->artisan('cliente:dominio', ['id' => 'gaboardi', 'dominio' => 'gaboardi.test', '--remover' => true])->assertFailed();
    }
}
