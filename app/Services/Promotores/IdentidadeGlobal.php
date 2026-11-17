<?php

namespace App\Services\Promotores;

use App\Models\ConsentimentoPromotor;
use App\Models\PromotorGlobal;
use App\Models\User;
use App\Support\Municipios;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Liga o promotor da agência à identidade central (uma pessoa, várias agências) e guarda os
 * consentimentos dele. Base da futura rede aberta de freelancers; hoje só registra e conta.
 */
class IdentidadeGlobal
{
    public const RAIO_PADRAO_KM = 30;

    /**
     * Acha ou cria a identidade pelo CPF, grava o código no promotor e mantém o centro dela
     * (cidade, coordenada, raio) e o vínculo com esta agência em dia.
     */
    public function vincular(User $usuario): ?PromotorGlobal
    {
        if (! $usuario->cpf_hash) {
            return null;
        }
        $global = PromotorGlobal::firstOrCreate(
            ['cpf_hash' => $usuario->cpf_hash],
            ['codigo' => (string) Str::ulid(), 'nome' => $usuario->nome],
        );
        $municipio = Municipios::achar($usuario->municipio_ibge);
        $global->fill([
            'nome' => $usuario->nome,
            'cidade_base' => $municipio['nome'] ?? $usuario->cidade_base,
            'uf_base' => $municipio['uf'] ?? $usuario->uf_base,
            'municipio_ibge' => $municipio['codigo'] ?? null,
            'latitude' => $municipio['latitude'] ?? null,
            'longitude' => $municipio['longitude'] ?? null,
            'raio_km' => $usuario->raio_km ?: self::RAIO_PADRAO_KM,
        ])->save();

        if ($usuario->promotor_global_id !== $global->codigo) {
            $usuario->forceFill(['promotor_global_id' => $global->codigo])->save();
        }
        $this->marcarAgencia($global->codigo, (string) tenant('id'), (bool) $usuario->ativo, Str::lower((string) $usuario->email));

        return $global;
    }

    private function marcarAgencia(string $codigo, string $tenantId, bool $ativo, string $email): void
    {
        $tabela = DB::connection(config('tenancy.database.central_connection'))->table('promotor_agencias');
        $agora = now();
        $existe = (clone $tabela)->where(['promotor_global_codigo' => $codigo, 'tenant_id' => $tenantId])->exists();
        if ($existe) {
            (clone $tabela)->where(['promotor_global_codigo' => $codigo, 'tenant_id' => $tenantId])->update(['ativo' => $ativo, 'email' => $email, 'updated_at' => $agora]);
        } else {
            $tabela->insert(['promotor_global_codigo' => $codigo, 'tenant_id' => $tenantId, 'ativo' => $ativo, 'email' => $email, 'created_at' => $agora, 'updated_at' => $agora]);
        }
    }

    public function registrarConsentimento(User $usuario, string $finalidade, string $versao, string $origem, ?string $ip, ?string $aparelho): void
    {
        $global = $this->vincular($usuario);
        if (! $global) {
            return;
        }
        ConsentimentoPromotor::create([
            'promotor_global_codigo' => $global->codigo,
            'tenant_id' => tenant('id'),
            'finalidade' => $finalidade,
            'versao' => $versao,
            'origem' => $origem,
            'ip' => $ip,
            'aparelho' => $aparelho ? mb_substr($aparelho, 0, 255) : null,
            'aceito_em' => now(),
        ]);
    }
}
