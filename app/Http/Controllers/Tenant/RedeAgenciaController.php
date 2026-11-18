<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ModoEmpresa;
use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Http\Controllers\Controller;
use App\Services\Rede\Autocadastro;
use App\Services\Rede\Vinculos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * A indústria cria pelo próprio painel (fase 9): a agência interna dela ou uma agência externa nova.
 * Só o admin da instalação da indústria. O vínculo já nasce ativo e o admin da agência recebe um link
 * de primeiro acesso, que aparece uma vez para a indústria repassar.
 */
class RedeAgenciaController extends Controller
{
    private function exigirAdminDaIndustria(Request $request): void
    {
        abort_unless(tenant()->tipo === TipoInstalacao::Industria && $request->user()->perfil === Perfil::AdminInstalacao, 403);
    }

    public function create(Request $request): View
    {
        $this->exigirAdminDaIndustria($request);

        return view('tenant.agencias.nova');
    }

    public function store(Request $request, Autocadastro $autocadastro, Vinculos $vinculos): RedirectResponse
    {
        $this->exigirAdminDaIndustria($request);
        $request->validate(['tipo_agencia' => ['required', Rule::in(['interna', 'externa'])]]);
        $modo = $request->input('tipo_agencia') === 'interna' ? ModoEmpresa::Interna : ModoEmpresa::Propria;
        $industria = tenant();

        $criada = $autocadastro->criar($industria, TipoInstalacao::Agencia, $modo, $request->only(['nome', 'cnpj', 'subdominio', 'admin_nome', 'admin_email']));
        $vinculos->criar($industria, $criada['tenant'], null, 'industria');

        return redirect()->route('agencias.index')
            ->with('status', ($modo === ModoEmpresa::Interna ? 'Agência interna' : 'Agência').' "'.$criada['tenant']->nome.'" criada e vinculada.')
            ->with('convite_agencia', ['nome' => $request->input('admin_nome'), 'url' => $criada['convite']]);
    }
}
