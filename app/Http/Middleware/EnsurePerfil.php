<?php

namespace App\Http\Middleware;

use App\Enums\Perfil;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso: ->middleware('perfil:admin_instalacao,admin_agencia')
 */
class EnsurePerfil
{
    public function handle(Request $request, Closure $next, string ...$perfis): Response
    {
        $usuario = $request->user();

        $permitidos = array_map(fn (string $p) => Perfil::from($p), $perfis);

        if (! $usuario || ! in_array($usuario->perfil, $permitidos, true)) {
            abort(403, 'Seu perfil não tem acesso a esta área.');
        }

        return $next($request);
    }
}
