<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Bi\PainelBi;
use App\Services\Operacao\Indicadores;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * BI da indústria e da agência: visão analítica do período, com o mesmo escopo de
 * permissões dos indicadores (cada perfil vê só o que já podia ver).
 */
class BiController extends Controller
{
    public function index(Request $request, Indicadores $indicadores, PainelBi $bi): View
    {
        abort_unless($request->user()->podeVerBi(), 403);

        // Sem período informado, o BI mostra os últimos 30 dias.
        $request->mergeIfMissing([
            'inicio' => today()->subDays(29)->toDateString(),
            'fim' => today()->toDateString(),
        ]);
        if (! $request->filled('inicio') || ! $request->filled('fim')) {
            $request->merge([
                'inicio' => $request->input('inicio') ?: today()->subDays(29)->toDateString(),
                'fim' => $request->input('fim') ?: today()->toDateString(),
            ]);
        }

        $opcoes = $indicadores->opcoes($request->user());
        $filtros = $indicadores->filtros($request, $opcoes);

        return view('tenant.bi.index', [
            'opcoes' => $opcoes,
            'bi' => $bi->gerar($request->user(), $filtros),
        ]);
    }
}
