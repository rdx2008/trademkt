<?php

namespace App\Http\Controllers\Central;

use App\Enums\TipoInstalacao;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Rules\Cnpj;
use App\Support\Fuso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        $request->merge(['cnpj' => Cnpj::limpar($request->input('cnpj')) ?: null]);
        $dados = $request->validate([
            'fuso' => ['nullable', Rule::in(array_keys(Fuso::OPCOES))],
            'cnpj' => ['nullable', new Cnpj, Rule::unique('tenants', 'cnpj')->where('tipo', $cliente->tipo->value)->ignore($cliente->id)],
        ], ['cnpj.unique' => 'Já existe uma empresa deste tipo com este CNPJ no sistema.']);
        $cliente->cnpj = $dados['cnpj'] ?? null;
        $cliente->white_label = $request->boolean('white_label');
        if (! empty($dados['fuso'])) {
            $cliente->fuso = $dados['fuso'];
        }
        $cliente->mostrar_creditos = $request->boolean('mostrar_creditos');
        $cliente->rede_jobs = $cliente->tipo === TipoInstalacao::Agencia && $request->boolean('rede_jobs');
        $cliente->save();

        return redirect()->route('central.clientes.index')->with('status', "Configurações de {$cliente->nome} atualizadas.");
    }
}
