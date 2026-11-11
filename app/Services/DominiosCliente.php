<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DominiosCliente
{
    public const RESERVADOS = ['www', 'admin', 'painel', 'api', 'mail', 'smtp', 'status', 'suporte', 'app'];

    public function resolver(array $dados): array
    {
        $tipo = $dados['tipo_endereco'] ?? 'proprio';
        Validator::make(['tipo_endereco' => $tipo], ['tipo_endereco' => ['required', Rule::in(['proprio', 'subdominio'])]])->validate();
        if ($tipo === 'subdominio') {
            $sub = Str::lower(trim((string) ($dados['subdominio'] ?? '')));
            Validator::make(['subdominio' => $sub], ['subdominio' => ['required', 'regex:/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', Rule::notIn(self::RESERVADOS)]])->validate();
            $base = Str::lower(trim((string) config('trade.dominio_clientes')));
            if (! $base) {
                throw ValidationException::withMessages(['subdominio' => 'O domínio base dos clientes ainda não foi configurado.']);
            }
            $dominios = [$sub.'.'.$base];
        } else {
            $entrada = $dados['dominios'] ?? [];
            $dominios = is_array($entrada) ? $entrada : preg_split('/[\s,]+/', (string) $entrada, -1, PREG_SPLIT_NO_EMPTY);
            $dominios = array_values(array_unique(array_map(fn ($d) => Str::lower(trim((string) $d)), $dominios)));
        }
        Validator::make(['dominios' => $dominios], [
            'dominios' => ['required', 'array', 'min:1', 'max:10'],
            'dominios.*' => ['required', 'regex:/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', Rule::unique('domains', 'domain'), Rule::notIn(config('tenancy.central_domains'))],
        ])->validate();

        return $dominios;
    }

    public function remover(Tenant $cliente, int $dominio): void
    {
        DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($cliente, $dominio) {
            $cliente = Tenant::lockForUpdate()->findOrFail($cliente->id);
            $registro = $cliente->domains()->findOrFail($dominio);
            if ($cliente->domains()->count() <= 1) {
                throw ValidationException::withMessages(['dominios' => 'Mantenha pelo menos um endereço para este cliente.']);
            }
            $principal = $cliente->dominioPrincipal();
            $registro->delete();
            if ($principal === $registro->domain) {
                $cliente->dominio_principal = $cliente->domains()->orderBy('id')->value('domain');
                $cliente->save();
            }
        });
    }
}
