<?php

namespace App\Http\Controllers\Api;

use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Http\Controllers\Controller;
use App\Models\Sku;
use App\Models\Visita;
use App\Services\Rede\Operacao;
use App\Services\Roteiros\RoteiroDoApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Roteiro do dia para o app do promotor (base da fase 3, que funciona offline).
 */
class RoteiroController extends Controller
{
    public function show(Request $request, RoteiroDoApp $montador): JsonResponse
    {
        // O promotor que entrou pela agência recebe as visitas de todas as indústrias atendidas.
        $operacao = app(Operacao::class);
        if (tenant()->tipo === TipoInstalacao::Agencia && $operacao->industriasDe(tenant())->isNotEmpty()) {
            return app(RoteiroAgenciaController::class)->show($request, $operacao, $montador);
        }

        $promotor = $request->user();
        abort_unless($promotor->perfil === Perfil::Promotor, 403, 'Só o promotor usa o roteiro do app.');

        $dados = $request->validate(['data' => ['nullable', 'date_format:Y-m-d']]);
        $data = isset($dados['data']) ? Carbon::parse($dados['data']) : today();

        return response()->json(['data' => $data->toDateString()] + $montador->montar($promotor, $data));
    }

    /** Foto do SKU para o app: só de indústrias que o promotor tem no roteiro (30 dias para trás ou para frente). */
    public function fotoSku(Request $request, Sku $sku): StreamedResponse
    {
        $promotor = $request->user();
        abort_unless($promotor->perfil === Perfil::Promotor, 403);

        $temIndustria = Visita::query()
            ->where('industria_id', $sku->industria_id)
            ->where('agencia_id', $promotor->agencia_id)
            ->whereHas('roteiro', fn ($q) => $q->where('promotor_id', $promotor->id))
            ->whereBetween('data', [today()->subDays(30)->toDateString(), today()->addDays(30)->toDateString()])
            ->exists();
        abort_unless($temIndustria && $sku->foto && Storage::disk('local')->exists($sku->foto), 404);

        return Storage::disk('local')->response($sku->foto, headers: ['Cache-Control' => 'private, max-age=604800']);
    }
}
