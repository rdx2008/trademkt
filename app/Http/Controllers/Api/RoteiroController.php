<?php

namespace App\Http\Controllers\Api;

use App\Enums\Perfil;
use App\Http\Controllers\Controller;
use App\Models\Roteiro;
use App\Models\Sku;
use App\Models\Visita;
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
    public function show(Request $request): JsonResponse
    {
        $promotor = $request->user();
        abort_unless($promotor->perfil === Perfil::Promotor, 403, 'Só o promotor usa o roteiro do app.');

        $dados = $request->validate(['data' => ['nullable', 'date_format:Y-m-d']]);
        $data = isset($dados['data']) ? Carbon::parse($dados['data']) : today();

        $roteiro = Roteiro::query()
            ->where('promotor_id', $promotor->id)
            ->whereDate('data', $data->toDateString())
            ->with(['visitas.pdv', 'visitas.industria', 'supervisor'])
            ->first();

        $visitas = $roteiro?->visitas ?? collect();

        // SKUs ativos de cada indústria do dia, enviados uma vez só (o app associa pela indústria).
        $industrias = $visitas->pluck('industria')->unique('id')->values()->map(fn ($industria) => [
            'id' => $industria->id,
            'nome' => $industria->nome,
            'skus' => Sku::query()
                ->where('industria_id', $industria->id)
                ->where('ativo', true)
                ->with('embalagens')
                ->orderBy('descricao')
                ->get()
                ->map(fn (Sku $sku) => [
                    'id' => $sku->id,
                    'codigo' => $sku->codigo,
                    'descricao' => $sku->descricao,
                    'categoria' => $sku->categoria,
                    'foto_url' => $sku->foto ? route('api.skus.foto', $sku) : null,
                    'embalagens' => $sku->embalagensOrdenadas()->map(fn ($e) => [
                        'tipo' => $e->tipo->value,
                        'quantidade' => $e->quantidade,
                        'gtin' => $e->gtin,
                    ])->all(),
                ])->all(),
        ])->all();

        return response()->json([
            'data' => $data->toDateString(),
            'roteiro_id' => $roteiro?->id,
            'supervisor' => $roteiro?->supervisor ? ['id' => $roteiro->supervisor->id, 'nome' => $roteiro->supervisor->nome] : null,
            'visitas' => $visitas->values()->map(fn (Visita $v, int $i) => [
                'id' => $v->id,
                'ordem' => $i + 1,
                'origem' => $v->origem->value,
                'status' => $v->status->value,
                'industria_id' => $v->industria_id,
                'pdv' => [
                    'id' => $v->pdv->id,
                    'nome' => $v->pdv->nome,
                    'cnpj' => $v->pdv->cnpj,
                    'endereco' => $v->pdv->enderecoCompleto(),
                    'lat' => $v->pdv->lat,
                    'lng' => $v->pdv->lng,
                    'raio_m' => $v->pdv->raio_m,
                ],
            ])->all(),
            'industrias' => $industrias,
        ]);
    }

    /** Foto do SKU para o app: só de indústrias que o promotor tem no roteiro (30 dias para trás ou para frente). */
    public function fotoSku(Request $request, Sku $sku): StreamedResponse
    {
        $promotor = $request->user();
        abort_unless($promotor->perfil === Perfil::Promotor, 403);

        $temIndustria = Visita::query()
            ->where('industria_id', $sku->industria_id)
            ->whereHas('roteiro', fn ($q) => $q->where('promotor_id', $promotor->id))
            ->whereBetween('data', [today()->subDays(30)->toDateString(), today()->addDays(30)->toDateString()])
            ->exists();

        abort_unless($temIndustria && $sku->foto && Storage::disk('local')->exists($sku->foto), 404);

        return Storage::disk('local')->response($sku->foto, headers: ['Cache-Control' => 'private, max-age=604800']);
    }
}
