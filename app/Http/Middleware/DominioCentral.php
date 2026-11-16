<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Separa, entre os domínios centrais, o do site (landing page) e o do painel do fornecedor.
 *
 * Com `INSTITUCIONAL_DOMINIO_SITE` definido (ex.: `bra.net.br`), a landing page só responde nesse
 * endereço e o painel central só nos outros domínios centrais (ex.: `trade.bra.net.br` e o domínio
 * antigo). Sem a variável, nada muda: todo domínio central serve as duas coisas.
 *
 * Uso: ->middleware('central.dominio:site') ou ->middleware('central.dominio:admin')
 */
class DominioCentral
{
    public function handle(Request $request, Closure $next, string $area): Response
    {
        $site = config('institucional.dominio_site');
        if (! $site) {
            return $next($request);
        }
        $noSite = strtolower($request->getHost()) === strtolower($site);

        if ($area === 'site') {
            if ($noSite) {
                return $next($request);
            }

            // No endereço do painel, a raiz leva ao login; as demais páginas do site não existem aqui.
            return $request->path() === '/' ? redirect()->route('central.login') : abort(404);
        }

        // Painel: nunca no endereço do site.
        abort_if($noSite, 404);

        return $next($request);
    }
}
