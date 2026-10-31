<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Rede\Parceria;
use App\Services\Rede\AcessoOperacao;
use App\Services\Rede\Parcerias;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AcessoParceriaController extends Controller
{
    public function abrir(Request $request, Parceria $parceria, Parcerias $parcerias, AcessoOperacao $acesso)
    {
        $perfil = $parcerias->agenciaDoUsuario($request->user());

        return redirect()->away($acesso->emitir($parceria, $perfil, $request->user()))->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }

    public function entrar(Request $request, AcessoOperacao $acesso)
    {
        $dados = $request->validate(['ticket' => ['required', 'string', 'size:64']]);
        $usuario = $acesso->entrar($dados['ticket']);
        Auth::guard('web')->login($usuario);
        $request->session()->regenerate();
        $request->session()->put('tenant_autenticado', tenant('id'));

        return redirect()->route('dashboard')->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }
}
