<?php

namespace App\Services\Rede;

use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Models\Agencia;
use App\Models\Industria;
use App\Models\Rede\AgenciaRede;
use App\Models\Rede\Parceria;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Parcerias
{
    public function agenciaDoUsuario(User $usuario): AgenciaRede
    {
        abort_unless(tenant()->tipo === TipoInstalacao::Agencia && $usuario->ativo, 403);
        $perfil = AgenciaRede::where('tenant_id', tenant('id'))->firstOrFail();
        abort_unless($usuario->perfil === Perfil::AdminInstalacao || ($usuario->perfil === Perfil::AdminAgencia && $usuario->agencia_id === $perfil->agencia_local_id), 403);

        return $perfil;
    }

    public function industriaDoUsuario(User $usuario): int
    {
        abort_unless(tenant()->tipo === TipoInstalacao::Industria && $usuario->ativo && $usuario->temPerfil(Perfil::AdminInstalacao, Perfil::GerenteTrade), 403);
        $id = $usuario->industria_id;
        abort_unless($id && Industria::whereKey($id)->where('ativo', true)->exists(), 403, 'Seu usuário precisa estar vinculado à indústria.');

        return (int) $id;
    }

    public function solicitar(Tenant $industria, int $industriaId, int $usuarioId, int $agenciaId, string $regiao, ?string $mensagem, ?string $uf = null, ?string $cidade = null): Parceria
    {
        abort_unless($industria->tipo === TipoInstalacao::Industria && $industria->ativo, 403);

        if ($uf !== null) {
            $cidade = trim((string) $cidade) ?: null;
            $regiao = $cidade ? $cidade.', '.$uf : $uf;
        }

        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($industria, $industriaId, $usuarioId, $agenciaId, $regiao, $mensagem, $uf, $cidade) {
            $agencia = AgenciaRede::whereKey($agenciaId)->where('aprovada', true)->where('disponivel', true)
                ->whereHas('tenant', fn ($q) => $q->where('ativo', true)->where('tipo', 'agencia'))->lockForUpdate()->first();
            if (! $agencia) {
                throw ValidationException::withMessages(['agencia_rede_id' => 'A agência não está disponível para novas parcerias.']);
            }
            $chave = ['industria_tenant_id' => $industria->id, 'industria_local_id' => $industriaId, 'agencia_rede_id' => $agenciaId];
            $parceria = Parceria::firstOrNew($chave);
            if ($parceria->exists && in_array($parceria->status, ['pendente', 'aceita'], true)) {
                throw ValidationException::withMessages(['agencia_rede_id' => 'Já existe uma parceria aceita ou uma solicitação pendente para esta agência.']);
            }
            $parceria->fill(['status' => 'pendente', 'regiao' => $regiao, 'uf' => $uf, 'cidade' => $cidade, 'regiao_anterior' => null, 'regiao_atualizada_em' => null, 'regiao_alterada_por' => null, 'mensagem' => $mensagem, 'solicitante_id' => $usuarioId, 'respondente_id' => null, 'resposta' => null, 'respondida_em' => null])->save();

            return $parceria;
        });
    }

    public function responder(Parceria $parceria, AgenciaRede $perfil, User $usuario, string $decisao, ?string $resposta): void
    {
        abort_unless($parceria->agencia_rede_id === $perfil->id && $perfil->aprovada, 403);
        abort_unless(in_array($decisao, ['aceita', 'recusada'], true), 422);
        DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($parceria, $perfil, $usuario, $decisao, $resposta) {
            $parceria = Parceria::lockForUpdate()->findOrFail($parceria->id);
            abort_unless($parceria->status === 'pendente', 409, 'Esta solicitação já foi respondida.');
            $industria = $parceria->industria;
            abort_unless($industria && $industria->ativo, 409, 'A instalação da indústria está suspensa.');
            if ($decisao === 'aceita') {
                // O banco da operação permanece na indústria. O vínculo central é a autoridade
                // para o acesso; uma tentativa repetida reaproveita a representação local.
                $nome = $perfil->tenant->nome;
                $industria->run(function () use ($perfil, $nome, $parceria) {
                    abort_unless(Industria::whereKey($parceria->industria_local_id)->where('ativo', true)->exists(), 409);
                    $local = Agencia::firstOrNew(['agencia_rede_id' => $perfil->id]);
                    if (! $local->exists) {
                        $local->forceFill(['nome' => $nome, 'ativo' => true])->save();
                    }
                });
            }
            $parceria->update(['status' => $decisao, 'resposta' => $resposta, 'respondente_id' => $usuario->id, 'respondida_em' => now()]);
        });
    }
}
