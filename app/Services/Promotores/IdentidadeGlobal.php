<?php

namespace App\Services\Promotores;

use App\Models\ConsentimentoPromotor;
use App\Models\PromotorGlobal;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Liga o promotor da agência à identidade central (uma pessoa, várias agências) e guarda os
 * consentimentos dele. Base da futura rede aberta de freelancers; hoje só registra.
 */
class IdentidadeGlobal
{
    /** Acha ou cria a identidade pelo CPF e grava o código no promotor. */
    public function vincular(User $usuario): ?PromotorGlobal
    {
        if (! $usuario->cpf_hash) {
            return null;
        }
        $global = PromotorGlobal::firstOrCreate(
            ['cpf_hash' => $usuario->cpf_hash],
            ['codigo' => (string) Str::ulid(), 'nome' => $usuario->nome, 'cidade_base' => $usuario->cidade_base, 'uf_base' => $usuario->uf_base],
        );
        if ($usuario->promotor_global_id !== $global->codigo) {
            $usuario->forceFill(['promotor_global_id' => $global->codigo])->save();
        }

        return $global;
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
