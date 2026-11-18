<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Vinculo;
use App\Services\Rede\Vinculos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Vínculos indústria–agência, feitos só pelo super admin. */
class VinculoController extends Controller
{
    public function index(): View
    {
        return view('central.vinculos.index', [
            'vinculos' => Vinculo::with(['industria', 'agencia'])->orderBy('industria_tenant_id')->orderBy('agencia_tenant_id')->get(),
            'industrias' => Tenant::where('tipo', 'industria')->where('ativo', true)->orderBy('nome')->get(),
            'agencias' => Tenant::where('tipo', 'agencia')->where('ativo', true)->orderBy('nome')->get(),
        ]);
    }

    public function store(Request $request, Vinculos $servico): RedirectResponse
    {
        $dados = $request->validate([
            'industria_tenant_id' => ['required', Rule::exists('tenants', 'id')->where('tipo', 'industria')],
            'agencia_tenant_id' => ['required', Rule::exists('tenants', 'id')->where('tipo', 'agencia')],
        ]);
        $vinculo = $servico->criar(Tenant::findOrFail($dados['industria_tenant_id']), Tenant::findOrFail($dados['agencia_tenant_id']), $request->input('regioes'));

        return redirect()->route('central.vinculos.index')
            ->with('status', "{$vinculo->agencia->nome} agora atende {$vinculo->industria->nome}.");
    }

    public function edit(Vinculo $vinculo): View
    {
        return view('central.vinculos.edit', ['vinculo' => $vinculo->load(['industria', 'agencia'])]);
    }

    public function update(Request $request, Vinculo $vinculo, Vinculos $servico): RedirectResponse
    {
        $servico->atualizar($vinculo, $request->input('regioes'), $request->boolean('ativo'));

        return redirect()->route('central.vinculos.index')->with('status', 'Vínculo atualizado.');
    }
}
