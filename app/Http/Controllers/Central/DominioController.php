<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\DominiosCliente;
use Illuminate\Http\Request;

class DominioController extends Controller
{
    public function index(Tenant $cliente)
    {
        return view('central.clientes.dominios', ['cliente' => $cliente->load('domains')]);
    }

    public function store(Request $request, Tenant $cliente, DominiosCliente $servico)
    {
        foreach ($servico->resolver($request->only(['tipo_endereco', 'subdominio', 'dominios'])) as $dominio) {
            $cliente->domains()->create(['domain' => $dominio]);
        }

        return back()->with('status', 'Endereço cadastrado. Configure o DNS e o domínio no serviço app do Coolify antes de usá-lo.');
    }

    public function principal(Tenant $cliente, int $dominio)
    {
        $cliente->dominio_principal = $cliente->domains()->findOrFail($dominio)->domain;
        $cliente->save();

        return back()->with('status', 'Endereço principal atualizado. O banco e os dados do cliente foram mantidos.');
    }

    public function destroy(Tenant $cliente, int $dominio, DominiosCliente $servico)
    {
        $servico->remover($cliente, $dominio);

        return back()->with('status', 'Endereço removido do sistema. Revise também o DNS e a configuração do Coolify.');
    }
}
