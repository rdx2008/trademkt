<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
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
    public function login(Request $request): JsonResponse
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

        // Um token por aparelho: login no mesmo aparelho substitui o anterior.
        $usuario->tokens()->where('name', $dados['dispositivo'])->delete();
        $token = $usuario->createToken($dados['dispositivo'], ['promotor'])->plainTextToken;

        $usuario->forceFill(['ultimo_acesso_em' => now()])->save();

        return response()->json([
            'token' => $token,
            'usuario' => $this->usuarioJson($usuario),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['usuario' => $this->usuarioJson($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sessão encerrada.']);
    }

    private function usuarioJson(User $usuario): array
    {
        $usuario->loadMissing('agencia');

        return [
            'id' => $usuario->id,
            'nome' => $usuario->nome,
            'email' => $usuario->email,
            'perfil' => $usuario->perfil->value,
            'tipo_vinculo' => $usuario->tipo_vinculo?->value,
            'agencia' => $usuario->agencia ? ['id' => $usuario->agencia->id, 'nome' => $usuario->agencia->nome] : null,
            'empresa' => ['codigo' => tenant('codigo'), 'nome' => tenant('nome')],
        ];
    }
}
