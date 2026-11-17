<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Services\ConteudoInstitucional;
use Illuminate\Http\JsonResponse;

/**
 * Versão do app do promotor: a mínima que ainda funciona com esta API, a mais nova publicada e o
 * link de download (o mesmo que a landing page mostra). O APK não passa por loja, então é o app que
 * pergunta e avisa o promotor.
 */
class VersaoDoAppController extends Controller
{
    public function show(): JsonResponse
    {
        $link = null;
        try {
            $link = app(ConteudoInstitucional::class)->publico()['app_android_url'] ?? null;
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'versao_minima' => (string) config('trade.app.versao_minima'),
            'versao_atual' => (string) config('trade.app.versao_atual'),
            'download_url' => is_string($link) && str_starts_with($link, 'https://') ? $link : null,
        ])->header('Cache-Control', 'public, max-age=300');
    }
}
