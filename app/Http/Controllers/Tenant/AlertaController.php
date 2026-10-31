<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Alerta;
use Illuminate\Http\Request;

class AlertaController extends Controller
{
    public function index(Request $r)
    {
        return view('tenant.alertas.index', ['alertas' => Alerta::visiveisPara($r->user())->latest()->paginate(25), 'naoLidas' => Alerta::visiveisPara($r->user())->whereNull('lida_em')->count()]);
    }

    public function ler(Request $r, int $alerta)
    {
        $a = Alerta::visiveisPara($r->user())->findOrFail($alerta);
        $a->update(['lida_em' => $a->lida_em ?? now()]);

        return back()->with('status', 'Alerta marcado como lido.');
    }
}
