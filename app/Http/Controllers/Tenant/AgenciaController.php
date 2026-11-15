<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Http\Controllers\Controller;
use App\Models\Agencia;
use App\Models\Tenant;
use App\Models\Vinculo;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Agências que atendem a instalação. O admin da indústria cria a agência interna ou uma agência
 * externa nova (RedeAgenciaController); a equipe de promotores é cadastrada pela própria agência.
 */
class AgenciaController extends Controller
{
    public function index(Request $request): View
    {
        $vinculos = Vinculo::where('industria_tenant_id', tenant('id'))->get()->keyBy('agencia_tenant_id');

        $empresas = Tenant::whereIn('id', $vinculos->keys())->get()->keyBy('id');

        return view('tenant.agencias.index', [
            'agencias' => Agencia::visiveisPara($request->user())->orderBy('nome')->get()
                ->each(fn (Agencia $a) => $a->setAttribute('vinculo', $a->agencia_tenant_id ? $vinculos->get($a->agencia_tenant_id) : null))
                ->each(fn (Agencia $a) => $a->setAttribute('empresa', $a->agencia_tenant_id ? $empresas->get($a->agencia_tenant_id) : null)),
            'podeCriar' => tenant()->tipo === TipoInstalacao::Industria && $request->user()->perfil === Perfil::AdminInstalacao,
        ]);
    }
}
