<?php

namespace App\Http\Controllers\Central;

use App\Enums\TipoInstalacao;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\ProvisionarCliente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClienteController extends Controller
{
    public function index(): View
    {
        return view('central.clientes.index', [
            'clientes' => Tenant::with('domains')->orderBy('nome')->get(),
        ]);
    }

    public function create(): View
    {
        return view('central.clientes.create', [
            'tipos' => TipoInstalacao::cases(),
        ]);
    }

    public function store(Request $request, ProvisionarCliente $provisionar): RedirectResponse
    {
        $dominios = preg_split('/[\s,]+/', (string) $request->input('dominios'), -1, PREG_SPLIT_NO_EMPTY);

        $resultado = $provisionar->executar([
            'id' => (string) $request->input('id'),
            'nome' => (string) $request->input('nome'),
            'tipo' => (string) $request->input('tipo'),
            'codigo' => $request->input('codigo'),
            'dominios' => $dominios,
            'admin_nome' => (string) $request->input('admin_nome'),
            'admin_email' => (string) $request->input('admin_email'),
            'admin_senha' => $request->input('admin_senha'),
        ]);

        return redirect()->route('central.clientes.index')->with('criado', [
            'nome' => $resultado['tenant']->nome,
            'url' => $resultado['tenant']->url(),
            'email' => $resultado['admin_email'],
            'senha' => $resultado['admin_senha'],
        ]);
    }

    public function alternarAtivo(Tenant $cliente): RedirectResponse
    {
        $cliente->update(['ativo' => ! $cliente->ativo]);

        return back()->with('status', $cliente->ativo
            ? "{$cliente->nome} reativado."
            : "{$cliente->nome} suspenso.");
    }
}
