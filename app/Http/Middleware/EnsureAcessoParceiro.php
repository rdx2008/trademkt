<?php

namespace App\Http\Middleware;

use App\Services\Rede\AcessoOperacao;
use Closure;
use Illuminate\Http\Request;

class EnsureAcessoParceiro
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->routeIs('logout', 'api.logout')) {
            return $next($request);
        }
        if ($usuario = $request->user()) {
            if ($request->hasSession() && $request->session()->has('tenant_autenticado')) {
                abort_unless($request->session()->get('tenant_autenticado') === tenant('id'), 403, 'Entre novamente pelo endereço desta instalação.');
            }
            abort_unless(app(AcessoOperacao::class)->sessaoPermitida($usuario), 403, 'O acesso da agência a esta instalação não está mais autorizado.');
            if ($usuario->agenciaDaRede()) {
                abort_unless($request->routeIs('dashboard', 'logout', 'usuarios.*', 'agencias.index', 'roteiros.*', 'visitas.mover', 'visitas.show', 'visitas.fotos', 'demandas.*', 'alertas.*', 'indicadores.*', 'contratos.index', 'contratos.edit', 'contratos.update', 'pdvs.index', 'skus.index', 'skus.foto', 'api.*'), 403, 'Este cadastro é administrado pela indústria.');
            }
        }

        return $next($request);
    }
}
