<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Industria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class IndustriaController extends Controller
{
    public function index(): View
    {
        return view('tenant.industrias.index', [
            'industrias' => Industria::withCount('usuarios')->orderBy('nome')->get(),
        ]);
    }

    public function create(): View
    {
        return view('tenant.industrias.form', ['industria' => new Industria(['ativo' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        Industria::create($this->validar($request));

        return redirect()->route('industrias.index')->with('status', 'Indústria cadastrada.');
    }

    public function edit(Industria $industria): View
    {
        return view('tenant.industrias.form', ['industria' => $industria]);
    }

    public function update(Request $request, Industria $industria): RedirectResponse
    {
        $industria->update($this->validar($request, $industria));

        return redirect()->route('industrias.index')->with('status', 'Indústria atualizada.');
    }

    private function validar(Request $request, ?Industria $industria = null): array
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:120'],
            'cnpj' => ['nullable', 'string', 'max:18', Rule::unique('industrias', 'cnpj')->ignore($industria?->id)],
            'segmento' => ['nullable', 'string', 'max:80'],
        ]);
        $dados['ativo'] = $request->boolean('ativo');

        return $dados;
    }
}
