<?php

namespace App\Services\Rede;

use App\Enums\TipoInstalacao;
use App\Models\Agencia;
use App\Models\Tenant;
use App\Models\Vinculo;
use App\Rules\Cnpj;
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
    public function criar(Tenant $industria, Tenant $agencia, mixed $regioes = null, string $origem = 'super_admin'): Vinculo
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

        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($industria, $agencia, $regioes, $origem) {
            $vinculo = Vinculo::create([
                'industria_tenant_id' => $industria->id,
                'agencia_tenant_id' => $agencia->id,
                'regioes' => $regioes,
                'ativo' => true,
                'status' => Vinculo::ATIVO,
                'origem' => $origem,
                'relacao' => 'cliente',
                'decidido_em' => now(),
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
        $vinculo->status = $ativo ? Vinculo::ATIVO : Vinculo::ENCERRADO;
        $vinculo->save();
        $this->sincronizarAgenciaLocal($vinculo);
    }

    /**
     * Pedido de vínculo feito por uma empresa a outra que já usa o sistema, achada pelo CNPJ (fase 9).
     * Fica pendente até a outra ponta aceitar; nada é liberado antes disso.
     *
     * @throws ValidationException
     */
    public function pedir(Tenant $de, string $cnpj, string $relacao = 'cliente'): Vinculo
    {
        $cnpj = Cnpj::limpar($cnpj);
        if (! Cnpj::valido($cnpj)) {
            throw ValidationException::withMessages(['cnpj' => 'Informe um CNPJ válido.']);
        }
        $tipoAlvo = $de->tipo === TipoInstalacao::Industria ? TipoInstalacao::Agencia : TipoInstalacao::Industria;
        $alvo = Tenant::where('tipo', $tipoAlvo->value)->where('cnpj', $cnpj)->first();
        if (! $alvo || ! $alvo->ativo) {
            throw ValidationException::withMessages(['cnpj' => ($tipoAlvo === TipoInstalacao::Agencia ? 'Nenhuma agência' : 'Nenhuma indústria').' com este CNPJ usa o sistema. Cadastre como nova.']);
        }
        [$industria, $agencia] = $de->tipo === TipoInstalacao::Industria ? [$de, $alvo] : [$alvo, $de];
        $existente = Vinculo::where('industria_tenant_id', $industria->id)->where('agencia_tenant_id', $agencia->id)->first();
        if ($existente && in_array($existente->status, [Vinculo::ATIVO, Vinculo::PENDENTE], true)) {
            throw ValidationException::withMessages(['cnpj' => $existente->status === Vinculo::ATIVO ? 'Estas empresas já estão vinculadas.' : 'Já existe um pedido pendente entre estas empresas.']);
        }
        $vinculo = $existente ?? new Vinculo(['industria_tenant_id' => $industria->id, 'agencia_tenant_id' => $agencia->id]);
        $vinculo->fill([
            'ativo' => false,
            'status' => Vinculo::PENDENTE,
            'origem' => $de->tipo === TipoInstalacao::Industria ? 'industria' : 'agencia',
            'relacao' => array_key_exists($relacao, Vinculo::RELACOES) ? $relacao : 'cliente',
            'decidido_em' => null,
        ])->save();

        return $vinculo;
    }

    /** Quem pode decidir o pedido: a ponta que não pediu. */
    public function decide(Vinculo $vinculo, Tenant $empresa): bool
    {
        return $vinculo->status === Vinculo::PENDENTE && (
            ($vinculo->origem === 'industria' && $vinculo->agencia_tenant_id === $empresa->id)
            || ($vinculo->origem === 'agencia' && $vinculo->industria_tenant_id === $empresa->id)
        );
    }

    /** @param  array<string, bool>  $permissoes  só quando a indústria aceita */
    public function aceitar(Vinculo $vinculo, array $permissoes = []): void
    {
        DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($vinculo, $permissoes) {
            $vinculo->fill(['status' => Vinculo::ATIVO, 'ativo' => true, 'decidido_em' => now()]);
            if ($permissoes) {
                $vinculo->permissoes = array_intersect_key($permissoes, Vinculo::PERMISSOES) + ($vinculo->permissoes ?? []);
            }
            $vinculo->save();
            $this->sincronizarAgenciaLocal($vinculo);
        });
    }

    public function recusar(Vinculo $vinculo): void
    {
        $vinculo->fill(['status' => Vinculo::RECUSADO, 'ativo' => false, 'decidido_em' => now()])->save();
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
