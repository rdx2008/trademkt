<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloqueia o acesso a clientes desativados no painel central.
 * Deve vir depois de InitializeTenancyByDomain.
 */
class EnsureClienteAtivo
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! tenant('ativo')) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Acesso suspenso. Fale com o suporte.'], 403);
            }

            abort(403, 'Acesso suspenso. Fale com o suporte.');
        }

        return $next($request);
    }
}
