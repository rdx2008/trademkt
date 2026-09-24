<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\Perfil;
use App\Http\Controllers\Controller;
use App\Models\Agencia;
use App\Models\Industria;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $usuario = $request->user();

        $contagem = null;
        if ($usuario->perfil === Perfil::AdminInstalacao) {
            $contagem = [
                'Indústrias' => Industria::count(),
                'Agências' => Agencia::count(),
                'Usuários' => User::count(),
                'Promotores' => User::where('perfil', Perfil::Promotor->value)->count(),
            ];
        }

        return view('tenant.dashboard', [
            'usuario' => $usuario,
            'contagem' => $contagem,
            'cliente' => tenant(),
        ]);
    }
}
