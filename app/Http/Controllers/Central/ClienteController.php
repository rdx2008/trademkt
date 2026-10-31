<?php

namespace App\Http\Controllers\Central;

use App\Enums\TipoInstalacao;
use App\Http\Controllers\Controller;
use App\Models\Rede\AgenciaRede;
use App\Models\Tenant;
use App\Services\DominiosCliente;
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
            'agenciasRede' => AgenciaRede::where('aprovada', true)->where('disponivel', true)->whereHas('tenant', fn ($q) => $q->where('ativo', true))->with('tenant')->get(),
        ]);
    }

    public function store(Request $request, ProvisionarCliente $provisionar, DominiosCliente $enderecos): RedirectResponse
    {
        $dominios = $enderecos->resolver($request->only(['tipo_endereco', 'subdominio', 'dominios']));

        $resultado = $provisionar->executar([
            'id' => (string) $request->input('id'),
            'nome' => (string) $request->input('nome'),
            'tipo' => (string) $request->input('tipo'),
            'codigo' => $request->input('codigo'),
            'dominios' => $dominios,
            'admin_nome' => (string) $request->input('admin_nome'),
            'admin_email' => (string) $request->input('admin_email'),
            'admin_senha' => $request->input('admin_senha'),
            'agencia_rede_id' => $request->input('agencia_rede_id'),
            'regiao_parceria' => $request->input('regiao_parceria'),
            'cidade_parceria' => $request->input('cidade_parceria'),
            'mensagem_parceria' => $request->input('mensagem_parceria'),
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
