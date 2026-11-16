<?php

namespace App\Support;

/**
 * Em quais domínios centrais registrar as rotas do painel. Em requisição web, só no domínio que está
 * sendo acessado: com vários domínios centrais (site, painel e o antigo), registrar todos faria o
 * `route()` gerar sempre o último, e os links e redirecionamentos pulariam de um domínio para outro
 * (e perderiam a sessão). No console (artisan, testes) registra todos.
 */
final class DominiosCentrais
{
    /**
     * @param  list<string>  $dominios
     * @return list<string>
     */
    public static function paraRegistrar(?string $hostAtual, array $dominios, bool $console): array
    {
        if ($console || $hostAtual === null) {
            return array_values($dominios);
        }
        $host = strtolower($hostAtual);
        foreach ($dominios as $d) {
            if (strtolower($d) === $host) {
                return [$d];
            }
        }

        // Domínio de cliente: nenhuma rota central. (A lista continua valendo para o resto.)
        return array_values($dominios);
    }
}
