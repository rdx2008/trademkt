<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class EmpresaController extends Controller
{
    /**
     * Descoberta pelo app: código da empresa => URL da instalação.
     * Não expõe nada além do necessário para o app se conectar.
     */
    public function show(string $codigo): JsonResponse
    {
        $tenant = Tenant::where('codigo', Str::lower(trim($codigo)))->first();

        if (! $tenant || ! $tenant->ativo || ! $tenant->url()) {
            return response()->json(['message' => 'Empresa não encontrada.'], 404);
        }

        return response()->json([
            'codigo' => $tenant->codigo,
            'nome' => $tenant->nome,
            'url' => $tenant->url(),
            'api' => $tenant->url().'/api/v1',
            'api_versao' => config('trade.api_versao'),
        ]);
    }
}
