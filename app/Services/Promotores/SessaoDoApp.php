<?php

namespace App\Services\Promotores;

use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Http\Controllers\Tenant\CrachaController;
use App\Models\User;

/**
 * Sessão do app do promotor: emite o token (um por aparelho) e monta o JSON do usuário. Usado pelo
 * login da agência (`/api/v1/login`) e pelo login único central (`/api/v1/entrar`).
 */
class SessaoDoApp
{
    /** @return string token em texto (Bearer) */
    public function emitirToken(User $usuario, string $dispositivo): string
    {
        // Um token por aparelho: login no mesmo aparelho substitui o anterior.
        $usuario->tokens()->where('name', $dispositivo)->delete();
        $token = $usuario->createToken($dispositivo, ['promotor'])->plainTextToken;
        $usuario->forceFill(['ultimo_acesso_em' => now()])->save();

        return $token;
    }

    /**
     * @param  ?string  $baseUrl  endereço da instalação, quando a chamada vem do domínio central
     * @return array<string, mixed>
     */
    public function usuarioJson(User $usuario, ?string $baseUrl = null): array
    {
        $usuario->loadMissing('agencia');
        $crachaUrl = null;
        if ($usuario->perfil === Perfil::Promotor && tenant()->tipo === TipoInstalacao::Agencia) {
            $codigo = CrachaController::codigo($usuario);
            $crachaUrl = $baseUrl ? rtrim($baseUrl, '/').'/verificar/'.$codigo : route('cracha.verificar', $codigo);
        }

        return [
            'id' => $usuario->id,
            'nome' => $usuario->nome,
            'email' => $usuario->email,
            'perfil' => $usuario->perfil->value,
            'tipo_vinculo' => $usuario->tipo_vinculo?->value,
            'agencia' => $usuario->agencia ? ['id' => $usuario->agencia->id, 'nome' => $usuario->agencia->nome] : null,
            'empresa' => ['codigo' => tenant('codigo'), 'nome' => tenant('nome')],
            'cracha_url' => $crachaUrl,
        ];
    }
}
