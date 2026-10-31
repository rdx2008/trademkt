<?php

namespace App\Http\Controllers\Central;

use App\Enums\Perfil;
use App\Http\Controllers\Controller;
use App\Models\Rede\AgenciaRede;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Rede\PerfilAgencia;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RedeAgenciaController extends Controller
{
    public function index()
    {
        return view('central.rede.index', ['agencias' => AgenciaRede::with(['tenant', 'regioes'])->orderBy('id')->get(), 'clientes' => Tenant::where('tipo', 'agencia')->where('ativo', true)->whereNotIn('id', AgenciaRede::select('tenant_id'))->orderBy('nome')->get()]);
    }

    public function store(Request $request, PerfilAgencia $servico)
    {
        $dados = $request->validate(['tenant_id' => ['required', Rule::exists('tenants', 'id')->where('tipo', 'agencia')->where('ativo', true), Rule::unique('agencias_rede', 'tenant_id')]]);
        $tenant = Tenant::findOrFail($dados['tenant_id']);
        $local = $tenant->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->whereNotNull('agencia_id')->value('agencia_id'));
        abort_unless($local, 422, 'A instalação precisa ter sua agência principal cadastrada.');
        $perfil = new AgenciaRede(['tenant_id' => $tenant->id, 'agencia_local_id' => $local]);
        $servico->salvar($perfil, $request->only(['apresentacao', 'email_comercial', 'regioes', 'aprovada', 'disponivel']), true);

        return redirect()->route('central.rede.index')->with('status', 'Perfil da agência cadastrado no diretório.');
    }

    public function edit(AgenciaRede $agencia)
    {
        return view('central.rede.edit', ['agencia' => $agencia->load(['tenant', 'regioes'])]);
    }

    public function update(Request $request, AgenciaRede $agencia, PerfilAgencia $servico)
    {
        $servico->salvar($agencia, $request->only(['apresentacao', 'email_comercial', 'regioes', 'aprovada', 'disponivel']), true);

        return redirect()->route('central.rede.index')->with('status', 'Perfil e aprovação atualizados.');
    }
}
