<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Rede\Operacao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Seletor de indústria no painel da agência. */
class OperacaoController extends Controller
{
    public function selecionar(Request $request, Tenant $industria, Operacao $operacao): RedirectResponse
    {
        abort_unless($operacao->industriasDe(tenant())->contains('id', $industria->id), 404);
        $request->session()->put('operacao', $industria->id);

        return redirect()->route('dashboard')->with('status', "Operação da {$industria->nome} aberta.");
    }
}
