<?php

namespace Tests;

use App\Enums\Perfil;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ProvisionarCliente;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $central = dirname(__DIR__).'/database/testing.sqlite';
        if (! file_exists($central)) {
            touch($central);
        }

        parent::setUp();

        $this->limparBancosDeClientes();
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        Tenant::all()->each->delete();
        $this->limparBancosDeClientes();
        File::deleteDirectory(storage_path('clientes'), preserve: true);
        File::put(storage_path('clientes/.gitignore'), "*\n!.gitignore\n");

        parent::tearDown();
    }

    private function limparBancosDeClientes(): void
    {
        foreach (glob(database_path('teste_cli_*')) ?: [] as $arquivo) {
            @unlink($arquivo);
        }
    }

    /** Cria um cliente completo e devolve o tenant. */
    protected function criarCliente(string $id = 'gaboardi', string $tipo = 'industria', ?string $dominio = null): Tenant
    {
        $resultado = app(ProvisionarCliente::class)->executar([
            'id' => $id,
            'nome' => ucfirst($id),
            'tipo' => $tipo,
            'dominios' => [$dominio ?? "{$id}.test"],
            'admin_nome' => 'Admin '.$id,
            'admin_email' => "admin@{$id}.test",
            'admin_senha' => 'senha1234',
        ]);

        return $resultado['tenant'];
    }

    /** Gera um CNPJ válido (só dígitos) a partir de um número base. */
    protected static function cnpj(int $base): string
    {
        $numeros = str_pad((string) $base, 8, '0', STR_PAD_LEFT).'0001';

        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $pesos) {
            $soma = 0;
            foreach ($pesos as $i => $peso) {
                $soma += (int) $numeros[$i] * $peso;
            }
            $resto = $soma % 11;
            $numeros .= $resto < 2 ? '0' : (string) (11 - $resto);
        }

        return $numeros;
    }

    /** Cria um usuário dentro do cliente (fora do contexto dele). */
    protected function criarUsuario(Tenant $tenant, Perfil $perfil, array $extra = []): User
    {
        return $tenant->run(fn () => User::create(array_merge([
            'nome' => $perfil->label(),
            'email' => $perfil->value.'@'.$tenant->id.'.test',
            'password' => 'senha1234',
            'perfil' => $perfil,
            'ativo' => true,
        ], $extra)));
    }
}
