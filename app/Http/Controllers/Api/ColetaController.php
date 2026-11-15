<?php

namespace App\Http\Controllers\Api;

use App\Enums\Perfil;
use App\Http\Controllers\Controller;
use App\Models\ColetaResposta;
use App\Models\ModeloColeta;
use App\Models\Sku;
use App\Models\Visita;
use App\Services\Coletas\Formulario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Respostas dos modelos de coleta, enviadas pelo app durante a visita (fase 10). Idempotentes por
 * UUID: reenviar a mesma resposta devolve a gravada; o mesmo UUID com conteúdo diferente é recusado.
 */
class ColetaController extends Controller
{
    public function store(Request $request, int $visita): JsonResponse
    {
        $u = $request->user();
        abort_unless($u->perfil === Perfil::Promotor && $u->ativo, 403);

        $dados = $request->validate([
            'uuid' => ['required', 'uuid'],
            'modelo_id' => ['required', 'integer'],
            'versao' => ['required', 'integer', 'min:1'],
            'sku_id' => ['nullable', 'integer'],
            'respostas' => ['required', 'array'],
            'respondida_em' => ['required', 'date'],
        ]);

        return DB::connection('tenant')->transaction(function () use ($u, $visita, $dados) {
            $v = Visita::where('agencia_id', $u->agencia_id)->whereHas('roteiro', fn ($q) => $q->where('promotor_id', $u->id))->lockForUpdate()->findOrFail($visita);
            $modelo = ModeloColeta::where('industria_id', $v->industria_id)->findOrFail($dados['modelo_id']);
            abort_if($dados['versao'] > $modelo->versao, 422, 'Versão do modelo desconhecida. Atualize o roteiro.');

            $skuId = $dados['sku_id'] ?? null;
            if ($skuId !== null) {
                abort_unless(Sku::where('industria_id', $v->industria_id)->whereKey($skuId)->exists(), 422, 'Produto inválido para esta indústria.');
            }
            $campos = $modelo->camposDaVersao($dados['versao']);
            $limpas = Formulario::validar($campos, $dados['respostas'], $skuId !== null);
            $hash = hash('sha256', json_encode([$v->id, $modelo->id, $dados['versao'], $skuId, $limpas], JSON_UNESCAPED_UNICODE));

            $existente = ColetaResposta::where('uuid', $dados['uuid'])->first();
            if ($existente) {
                abort_unless($existente->hash === $hash && $existente->visita_id === $v->id, 409, 'Esta resposta já foi enviada com outro conteúdo.');

                return response()->json(['id' => $existente->id, 'repetida' => true]);
            }

            $resposta = ColetaResposta::create([
                'uuid' => $dados['uuid'], 'visita_id' => $v->id, 'modelo_id' => $modelo->id, 'versao' => $dados['versao'],
                'sku_id' => $skuId, 'respostas' => $limpas, 'hash' => $hash,
                'respondida_em' => Carbon::parse($dados['respondida_em'])->setTimezone(config('app.timezone')), 'recebida_em' => now(),
            ]);

            return response()->json(['id' => $resposta->id, 'repetida' => false], 201);
        });
    }
}
