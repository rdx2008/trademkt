<?php

namespace App\Http\Middleware;

use App\Support\Contexto;
use Closure;
use Illuminate\Http\Request;

/**
 * A sessão aberta numa instalação não vale em outra, mesmo com o mesmo cookie. Compara com a
 * instalação em que o usuário entrou (a agência), não com a indústria que ela esteja operando.
 */
class EnsureInstalacaoDaSessao
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && $request->hasSession() && $request->session()->has('tenant_autenticado')) {
            abort_unless($request->session()->get('tenant_autenticado') === (Contexto::instalacao()?->id ?? tenant('id')), 403, 'Entre novamente pelo endereço desta instalação.');
        }

        return $next($request);
    }
}
