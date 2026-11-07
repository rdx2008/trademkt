<?php

namespace App\Services\Rede;

use App\Enums\TipoInstalacao;
use App\Models\Agencia;
use App\Models\Tenant;
use App\Models\Vinculo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vínculo indústria–agência feito pelo super admin. Ao vincular, a agência passa a existir no
 * banco da indústria como uma linha de `agencias` com `agencia_tenant_id`, que é o que contratos,
 * visitas e demandas referenciam. Desativar o vínculo desativa essa linha (e o acesso da agência);
 * os dados da operação são preservados.
 */
class Vinculos
{
    /**
     * @param  mixed  $regioes  lista [{uf, cidade}] ou vazio (todas as regiões)
     *
     * @throws ValidationException
     */
    public function criar(Tenant $industria, Tenant $agencia, mixed $regioes = null): Vinculo
    {
        if ($industria->tipo !== TipoInstalacao::Industria || $agencia->tipo !== TipoInstalacao::Agencia) {
            throw ValidationException::withMessages(['agencia_tenant_id' => 'Escolha uma instalação de indústria e uma de agência.']);
        }
        if (! $industria->ativo || ! $agencia->ativo) {
            throw ValidationException::withMessages(['agencia_tenant_id' => 'As duas instalações precisam estar ativas.']);
        }
        if (Vinculo::where('industria_tenant_id', $industria->id)->where('agencia_tenant_id', $agencia->id)->exists()) {
            throw ValidationException::withMessages(['agencia_tenant_id' => 'Esta agência já está vinculada a esta indústria.']);
        }
        $regioes = Regioes::normalizar($regioes);

        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($industria, $agencia, $regioes) {
            $vinculo = Vinculo::create([
                'industria_tenant_id' => $industria->id,
                'agencia_tenant_id' => $agencia->id,
                'regioes' => $regioes,
                'ativo' => true,
            ]);
            $this->sincronizarAgenciaLocal($vinculo);

            return $vinculo;
        });
    }

    /** @throws ValidationException */
    public function atualizar(Vinculo $vinculo, mixed $regioes, bool $ativo): void
    {
        $vinculo->regioes = Regioes::normalizar($regioes);
        $vinculo->ativo = $ativo;
        $vinculo->save();
        $this->sincronizarAgenciaLocal($vinculo);
    }

    /** Cria ou atualiza a representação da agência no banco da indústria. */
    public function sincronizarAgenciaLocal(Vinculo $vinculo): void
    {
        $agencia = $vinculo->agencia;
        $vinculo->industria->run(function () use ($vinculo, $agencia) {
            $local = Agencia::firstOrNew(['agencia_tenant_id' => $agencia->id]);
            $local->forceFill(['nome' => $agencia->nome, 'ativo' => $vinculo->ativo && $agencia->ativo])->save();
        });
    }

    /** Linha local da agência vinculada, no banco da indústria em uso. */
    public function agenciaLocal(Tenant $agencia): ?Agencia
    {
        return Agencia::where('agencia_tenant_id', $agencia->id)->first();
    }
}
