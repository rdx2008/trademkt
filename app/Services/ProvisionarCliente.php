<?php

namespace App\Services;

use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Models\Agencia;
use App\Models\Industria;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cria um cliente completo: registro central, domínios, banco, pasta de
 * arquivos, empresa dona da instalação e o primeiro admin.
 */
class ProvisionarCliente
{
    /**
     * @param  array{id:string,nome:string,tipo:string,dominios:array<int,string>,codigo?:?string,admin_nome:string,admin_email:string,admin_senha?:?string}  $dados
     * @return array{tenant: Tenant, admin_email: string, admin_senha: string}
     *
     * @throws ValidationException
     */
    public function executar(array $dados): array
    {
        $dados['id'] = Str::lower(trim($dados['id'] ?? ''));
        $dados['codigo'] = Str::lower(trim($dados['codigo'] ?? '')) ?: $dados['id'];
        $dados['dominios'] = array_values(array_unique(array_filter(array_map(
            fn ($d) => Str::lower(trim((string) $d)),
            (array) ($dados['dominios'] ?? [])
        ))));

        $validados = $this->validar($dados);

        $senha = ($validados['admin_senha'] ?? null) ?: Str::password(12, symbols: false);

        /** @var Tenant $tenant */
        $tenant = Tenant::create([
            'id' => $validados['id'],
            'nome' => $validados['nome'],
            'tipo' => $validados['tipo'],
            'codigo' => $validados['codigo'],
            'ativo' => true,
        ]);

        try {
            foreach ($validados['dominios'] as $dominio) {
                $tenant->domains()->create(['domain' => $dominio]);
            }

            $this->criarPastas($tenant);

            $tenant->run(function () use ($validados, $senha) {
                $industriaId = null;
                $agenciaId = null;

                // A empresa dona da instalação já nasce cadastrada.
                if ($validados['tipo'] === TipoInstalacao::Industria->value) {
                    $industriaId = Industria::create(['nome' => $validados['nome']])->id;
                } else {
                    $agenciaId = Agencia::create(['nome' => $validados['nome']])->id;
                }

                User::create([
                    'nome' => $validados['admin_nome'],
                    'email' => Str::lower($validados['admin_email']),
                    'password' => $senha,
                    'perfil' => Perfil::AdminInstalacao,
                    'industria_id' => $industriaId,
                    'agencia_id' => $agenciaId,
                    'ativo' => true,
                ]);
            });
        } catch (Throwable $e) {
            // Desfaz tudo (o evento de exclusão apaga o banco do cliente).
            $tenant->delete();
            throw $e;
        }

        return [
            'tenant' => $tenant->fresh(),
            'admin_email' => Str::lower($validados['admin_email']),
            'admin_senha' => $senha,
        ];
    }

    /** @throws ValidationException */
    private function validar(array $dados): array
    {
        $centrais = config('tenancy.central_domains');

        return Validator::make($dados, [
            'id' => ['required', 'regex:/^[a-z][a-z0-9_]{2,39}$/', Rule::unique('tenants', 'id')],
            'nome' => ['required', 'string', 'max:120'],
            'tipo' => ['required', Rule::enum(TipoInstalacao::class)],
            'codigo' => ['required', 'regex:/^[a-z0-9_-]{3,40}$/', Rule::unique('tenants', 'codigo')],
            'dominios' => ['required', 'array', 'min:1'],
            'dominios.*' => [
                'required',
                'regex:/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/',
                Rule::unique('domains', 'domain'),
                Rule::notIn($centrais),
            ],
            'admin_nome' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email'],
            'admin_senha' => ['nullable', 'string', 'min:8'],
        ], [
            'id.regex' => 'O identificador deve começar com letra e ter só letras minúsculas, números e _ (3 a 40).',
            'codigo.regex' => 'O código deve ter só letras minúsculas, números, _ ou - (3 a 40).',
            'dominios.*.regex' => 'Domínio inválido: :input',
            'dominios.*.unique' => 'O domínio :input já está em uso.',
            'dominios.*.not_in' => 'O domínio :input é do painel central.',
        ])->validate();
    }

    private function criarPastas(Tenant $tenant): void
    {
        $base = storage_path('clientes/'.$tenant->getTenantKey());

        foreach (['app/public', 'app/fotos', 'framework/cache', 'logs'] as $sub) {
            File::ensureDirectoryExists($base.'/'.$sub, 0775);
        }
    }
}
