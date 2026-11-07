<?php

namespace App\Http\Controllers\Api;

use App\Enums\Perfil;
use App\Http\Controllers\Controller;
use App\Services\Rede\Operacao;
use App\Services\Roteiros\RoteiroDoApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Roteiro do dia do promotor que entrou pela agência: soma as visitas de todas as indústrias
 * que a agência atende, cada uma lida do próprio banco.
 */
class RoteiroAgenciaController extends Controller
{
    public function show(Request $request, Operacao $operacao, RoteiroDoApp $montador): JsonResponse
    {
        $promotor = $request->user();
        abort_unless($promotor->perfil === Perfil::Promotor, 403, 'Só o promotor usa o roteiro do app.');

        $dados = $request->validate(['data' => ['nullable', 'date_format:Y-m-d']]);
        $data = isset($dados['data']) ? Carbon::parse($dados['data']) : today();
        $agencia = tenant();

        $visitas = [];
        $industrias = [];
        $supervisor = null;
        foreach ($operacao->industriasDe($agencia) as $industria) {
            if (! $operacao->abrir($agencia, $industria, $promotor)) {
                continue;
            }
            try {
                $parte = $montador->montar($promotor, $data);
            } finally {
                $operacao->fechar($promotor);
            }
            $supervisor ??= $parte['supervisor'];
            array_push($visitas, ...$parte['visitas']);
            array_push($industrias, ...$parte['industrias']);
        }

        // Ordem única do dia: a de cada indústria, na ordem em que a agência as atende.
        foreach ($visitas as $i => &$visita) {
            $visita['ordem'] = $i + 1;
        }

        return response()->json([
            'data' => $data->toDateString(),
            'roteiro_id' => null,
            'supervisor' => $supervisor,
            'visitas' => $visitas,
            'industrias' => $industrias,
        ]);
    }
}
