<?php

namespace App\Http\Controllers\Api;

use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Http\Controllers\Controller;
use App\Services\Promotores\Cadastro;
use App\Services\Promotores\FichaOperacional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** Autoatendimento do promotor no app: trocar a própria senha e ver a própria produção do mês. */
class MinhaContaController extends Controller
{
    /** Troca a senha. Os outros aparelhos do promotor nesta agência são desconectados; este continua. */
    public function senha(Request $request, Cadastro $cadastro): JsonResponse
    {
        $dados = $request->validate([
            'senha_atual' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [], ['password' => 'nova senha']);

        $usuario = $request->user();
        if (! Hash::check($dados['senha_atual'], $usuario->password)) {
            throw ValidationException::withMessages(['senha_atual' => 'A senha atual não confere.']);
        }
        if (Hash::check($dados['password'], $usuario->password)) {
            throw ValidationException::withMessages(['password' => 'Escolha uma senha diferente da atual.']);
        }

        $usuario->forceFill(['password' => $dados['password']])->save();
        $usuario->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();
        $cadastro->auditar($usuario, 'senha_alterada', $usuario);

        return response()->json(['message' => 'Senha alterada.']);
    }

    /**
     * SKUs trabalhados pelo próprio promotor no mês (de todas as indústrias atendidas), por dia.
     * Ruptura não conta. É o relatório que o freelancer usa para conferir o que tem a receber.
     */
    public function producao(Request $request, FichaOperacional $ficha): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->perfil === Perfil::Promotor && tenant()->tipo === TipoInstalacao::Agencia, 403);

        $mes = $request->validate(['mes' => ['nullable', 'date_format:Y-m']])['mes'] ?? now()->format('Y-m');
        $inicio = $mes.'-01';
        $fim = Carbon::parse($inicio)->endOfMonth()->toDateString();
        $linhas = $ficha->skusTrabalhados(tenant(), $usuario->id, $inicio, $fim);

        $dias = $linhas->groupBy('data')->map(fn ($itens, $data) => [
            'data' => $data,
            'total' => $itens->count(),
            'itens' => $itens->map(fn ($l) => [
                'pdv' => $l->pdv,
                'industria' => $l->industria,
                'codigo' => $l->codigo,
                'descricao' => $l->descricao,
                'situacao' => $l->situacao,
            ])->values(),
        ])->values();

        return response()->json([
            'mes' => $mes,
            'total' => $linhas->count(),
            'visitas' => $linhas->pluck('visita_id')->unique()->count(),
            'dias' => $dias,
        ]);
    }
}
