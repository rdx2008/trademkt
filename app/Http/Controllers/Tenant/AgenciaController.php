<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Agencia;
use App\Models\Vinculo;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Agências que atendem a instalação: só consulta. O vínculo entre indústria e agência é feito
 * pelo painel central; a equipe de promotores é cadastrada pela própria agência.
 */
class AgenciaController extends Controller
{
    public function index(Request $request): View
    {
        $vinculos = Vinculo::where('industria_tenant_id', tenant('id'))->get()->keyBy('agencia_tenant_id');

        return view('tenant.agencias.index', [
            'agencias' => Agencia::visiveisPara($request->user())->orderBy('nome')->get()
                ->each(fn (Agencia $a) => $a->setAttribute('vinculo', $a->agencia_tenant_id ? $vinculos->get($a->agencia_tenant_id) : null)),
        ]);
    }
}
