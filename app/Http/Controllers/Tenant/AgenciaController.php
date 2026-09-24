<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Agencia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AgenciaController extends Controller
{
    public function index(): View
    {
        return view('tenant.agencias.index', [
            'agencias' => Agencia::withCount('usuarios')->orderBy('nome')->get(),
        ]);
    }

    public function create(): View
    {
        return view('tenant.agencias.form', ['agencia' => new Agencia(['ativo' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        Agencia::create($this->validar($request));

        return redirect()->route('agencias.index')->with('status', 'Agência cadastrada.');
    }

    public function edit(Agencia $agencia): View
    {
        return view('tenant.agencias.form', ['agencia' => $agencia]);
    }

    public function update(Request $request, Agencia $agencia): RedirectResponse
    {
        $agencia->update($this->validar($request, $agencia));

        return redirect()->route('agencias.index')->with('status', 'Agência atualizada.');
    }

    private function validar(Request $request, ?Agencia $agencia = null): array
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:120'],
            'cnpj' => ['nullable', 'string', 'max:18', Rule::unique('agencias', 'cnpj')->ignore($agencia?->id)],
        ]);
        $dados['ativo'] = $request->boolean('ativo');

        return $dados;
    }
}
