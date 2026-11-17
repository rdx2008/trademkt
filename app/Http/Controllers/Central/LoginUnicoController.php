<?php

namespace App\Http\Controllers\Central;

use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Promotores\SessaoDoApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Login único do app do promotor: e-mail e senha, sem código da agência. O sistema descobre em
 * quais agências a pessoa está cadastrada (índice central por e-mail) e entra em todas em que a
 * senha confere, devolvendo um token por agência. A senha continua guardada só em cada agência.
 * Quem tem senha diferente em outra agência entra nela por "adicionar agência".
 */
class LoginUnicoController extends Controller
{
    public function entrar(Request $request, EmpresaController $empresas, SessaoDoApp $sessao): JsonResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'dispositivo' => ['required', 'string', 'max:100'],
        ]);
        $email = Str::lower(trim($dados['email']));

        $agencias = DB::connection(config('tenancy.database.central_connection'))->table('promotor_agencias')
            ->where('email', $email)->where('ativo', true)->orderBy('id')->pluck('tenant_id')->unique();

        $contas = [];
        foreach ($agencias as $tenantId) {
            $tenant = Tenant::find($tenantId);
            if (! $tenant || ! $tenant->ativo || $tenant->tipo !== TipoInstalacao::Agencia || ! $tenant->url()) {
                continue;
            }
            try {
                $conta = $tenant->run(function () use ($email, $dados, $tenant, $empresas, $sessao) {
                    $usuario = User::where('email', $email)->where('perfil', Perfil::Promotor->value)->first();
                    if (! $usuario || ! $usuario->ativo || ! Hash::check($dados['password'], $usuario->password)) {
                        return null;
                    }

                    return [
                        'empresa' => $empresas->descricao($tenant),
                        'token' => $sessao->emitirToken($usuario, $dados['dispositivo']),
                        'usuario' => $sessao->usuarioJson($usuario, $tenant->url()),
                    ];
                });
            } catch (Throwable $e) {
                report($e);

                continue;
            }
            if ($conta) {
                $contas[] = $conta;
            }
        }

        if ($agencias->isEmpty()) {
            Hash::make($dados['password']); // gasta o mesmo tempo de uma conferência
        }
        if ($contas === []) {
            throw ValidationException::withMessages(['email' => 'E-mail ou senha inválidos.']);
        }

        return response()->json(['contas' => $contas]);
    }
}
