<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** A sessão aberta numa instalação não vale em outra, mesmo com o mesmo cookie. */
class EnsureInstalacaoDaSessao
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && $request->hasSession() && $request->session()->has('tenant_autenticado')) {
            abort_unless($request->session()->get('tenant_autenticado') === tenant('id'), 403, 'Entre novamente pelo endereço desta instalação.');
        }

        return $next($request);
    }
}
