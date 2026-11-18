<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Promotores\SessaoDoApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Login do app do promotor. Devolve um token Sanctum (Bearer).
 */
class AuthController extends Controller
{
    public function login(Request $request, SessaoDoApp $sessao): JsonResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'dispositivo' => ['required', 'string', 'max:100'],
        ]);

        $usuario = User::where('email', Str::lower($dados['email']))->first();

        if (! $usuario || ! Hash::check($dados['password'], $usuario->password)) {
            throw ValidationException::withMessages(['email' => 'E-mail ou senha inválidos.']);
        }

        if (! $usuario->ativo) {
            throw ValidationException::withMessages(['email' => 'Usuário desativado.']);
        }

        if (! $usuario->perfil->usaApp()) {
            throw ValidationException::withMessages(['email' => 'Este perfil usa o painel web, não o app.']);
        }

        return response()->json([
            'token' => $sessao->emitirToken($usuario, $dados['dispositivo']),
            'usuario' => $sessao->usuarioJson($usuario),
        ]);
    }

    public function me(Request $request, SessaoDoApp $sessao): JsonResponse
    {
        return response()->json(['usuario' => $sessao->usuarioJson($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sessão encerrada.']);
    }
}
