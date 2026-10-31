<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\View\View;
use Stancl\Tenancy\Database\Models\Domain;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('central.dashboard', [
            'total' => Tenant::count(),
            'ativos' => Tenant::where('ativo', true)->count(),
            'industrias' => Tenant::where('tipo', 'industria')->count(),
            'agencias' => Tenant::where('tipo', 'agencia')->count(),
            'dominios' => Domain::count(),
            'semDominio' => Tenant::doesntHave('domains')->count(),
            'recentes' => Tenant::with('domains')->latest()->limit(8)->get(),
        ]);
    }
}
