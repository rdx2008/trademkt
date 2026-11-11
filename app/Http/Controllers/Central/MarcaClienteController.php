<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Marca própria por cliente, decidida pelo fornecedor: liberar ou não a personalização
 * (cor, logo e nome) e escolher se o rodapé mostra "Desenvolvido por Trade Promo".
 * Por padrão a marca própria está liberada e os créditos ficam ocultos.
 */
class MarcaClienteController extends Controller
{
    public function edit(Tenant $cliente): View
    {
        return view('central.clientes.marca', ['cliente' => $cliente]);
    }

    public function update(Request $request, Tenant $cliente): RedirectResponse
    {
        $cliente->white_label = $request->boolean('white_label');
        $cliente->mostrar_creditos = $request->boolean('mostrar_creditos');
        $cliente->save();

        return redirect()->route('central.clientes.index')->with('status', "Marca de {$cliente->nome} atualizada.");
    }
}
