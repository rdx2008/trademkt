<?php

namespace App\Services\Rede;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\Rede\AcessoParceria;
use App\Models\Rede\AgenciaRede;
use App\Models\Rede\Parceria;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AcessoOperacao
{
    public function origemPermitida(AgenciaRede $perfil, int $usuarioId): ?array
    {
        $origem = $perfil->tenant;
        if (! $origem || ! $origem->ativo || ! $perfil->aprovada) {
            return null;
        }

        return $origem->run(function () use ($perfil, $usuarioId) {
            $usuario = User::find($usuarioId);
            if (! $usuario || ! $usuario->ativo || $usuario->parceiro_origem_tenant) {
                return null;
            }
            $permitido = $usuario->perfil === Perfil::AdminInstalacao || ($usuario->perfil === Perfil::AdminAgencia && $usuario->agencia_id === $perfil->agencia_local_id);

            return $permitido ? ['nome' => $usuario->nome, 'id' => $usuario->id] : null;
        });
    }

    public function emitir(Parceria $parceria, AgenciaRede $perfil, User $usuario): string
    {
        abort_unless($parceria->agencia_rede_id === $perfil->id && $parceria->status === 'aceita' && $parceria->industria?->ativo, 403);
        abort_unless($this->origemPermitida($perfil, $usuario->id), 403);
        $token = Str::random(64);
        AcessoParceria::where('expira_em', '<', now())->delete();
        AcessoParceria::create(['parceria_id' => $parceria->id, 'usuario_origem_id' => $usuario->id, 'token_hash' => hash('sha256', $token), 'expira_em' => now()->addMinute()]);
        $url = $parceria->industria->url();
        abort_unless($url, 409, 'A instalação da indústria precisa de um domínio.');

        return $url.'/parcerias/entrar?ticket='.$token;
    }

    public function entrar(string $token): User
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($token) {
            $ticket = AcessoParceria::where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            abort_unless($ticket && $ticket->expira_em->isFuture(), 403, 'Acesso expirado. Abra a operação novamente pelo painel da agência.');
            $parceria = Parceria::with('agencia.tenant')->find($ticket->parceria_id);
            abort_unless($parceria && $parceria->industria_tenant_id === tenant('id') && $parceria->status === 'aceita', 403);
            $origem = $this->origemPermitida($parceria->agencia, $ticket->usuario_origem_id);
            abort_unless($origem, 403);
            $agencia = Agencia::where('agencia_rede_id', $parceria->agencia_rede_id)->where('ativo', true)->firstOrFail();
            $usuario = User::where('parceiro_origem_tenant', $parceria->agencia->tenant_id)->where('parceiro_origem_usuario_id', $origem['id'])->first();
            if (! $usuario) {
                $usuario = new User;
                $usuario->forceFill([
                    'nome' => $origem['nome'],
                    'email' => 'rede.'.Str::uuid().'@parceiros.invalid',
                    'password' => Str::random(64),
                    'perfil' => Perfil::AdminAgencia,
                    'agencia_id' => $agencia->id,
                    'ativo' => true,
                    'parceiro_origem_tenant' => $parceria->agencia->tenant_id,
                    'parceiro_origem_usuario_id' => $origem['id'],
                ])->save();
            }
            abort_unless($usuario->ativo && $usuario->perfil === Perfil::AdminAgencia && $usuario->agencia_id === $agencia->id, 403);
            $ticket->delete();

            return $usuario;
        });
    }

    public function sessaoPermitida(User $usuario): bool
    {
        if (! $usuario->ativo) {
            return false;
        }
        if (! $usuario->agenciaDaRede()) {
            return ! $usuario->parceiro_origem_tenant;
        }
        $agencia = $usuario->agencia;
        if (! $agencia->ativo) {
            return false;
        }
        $parceria = Parceria::with('agencia.tenant')->where('industria_tenant_id', tenant('id'))->where('agencia_rede_id', $agencia->agencia_rede_id)->where('status', 'aceita')->first();
        if (! $parceria || ! $parceria->agencia->aprovada || ! $parceria->agencia->tenant?->ativo) {
            return false;
        }
        if (! $usuario->parceiro_origem_tenant) {
            return true;
        }
        if (! $usuario->ativo || $usuario->perfil !== Perfil::AdminAgencia) {
            return false;
        }

        return $parceria->agencia->tenant_id === $usuario->parceiro_origem_tenant && $this->origemPermitida($parceria->agencia, $usuario->parceiro_origem_usuario_id) !== null;
    }
}
