<?php

namespace App\Http\Controllers\Api;

use App\Enums\Perfil;
use App\Enums\StatusVisita;
use App\Http\Controllers\Controller;
use App\Models\Visita;
use App\Services\Operacao\AlertasOperacao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckInController extends Controller
{
    public function __invoke(Request $request, int $visita): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->perfil === Perfil::Promotor && $usuario->ativo, 403);

        $dados = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'gps_em' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'aparelho_em' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'gps_simulado' => ['required', 'boolean', 'declined'],
        ]);

        return DB::connection('tenant')->transaction(function () use ($usuario, $visita, $dados) {
            $registro = Visita::query()
                ->whereHas('roteiro', fn ($q) => $q->where('promotor_id', $usuario->id))
                ->where('agencia_id', $usuario->agencia_id)
                ->lockForUpdate()->findOrFail($visita);

            // A primeira gravação é definitiva, inclusive em reenvio após conclusão.
            if ($registro->check_in !== null) {
                return response()->json(['id' => $registro->id, 'status' => $registro->status->value, 'check_in' => $registro->check_in]);
            }

            abort_unless(in_array($registro->status, [StatusVisita::Previsto, StatusVisita::NaoExecutada], true), 409, 'A visita não está prevista.');
            $sincronizacaoTardia = $registro->status === StatusVisita::NaoExecutada;

            $gps = Carbon::parse($dados['gps_em'])->setTimezone(config('app.timezone'));
            if ($gps->toDateString() !== $registro->data->toDateString() || $gps->isAfter(now()->addMinutes(5))) {
                throw ValidationException::withMessages(['gps_em' => 'O GPS deve registrar o dia da visita e não pode estar no futuro.']);
            }

            $pdv = $registro->pdv;
            if (! $pdv->ativo || $pdv->lat === null || $pdv->lng === null) {
                throw ValidationException::withMessages(['pdv' => 'O PDV precisa estar ativo e ter coordenadas cadastradas.']);
            }

            $lat = deg2rad((float) $dados['lat']);
            $latPdv = deg2rad((float) $pdv->lat);
            $deltaLng = deg2rad((float) $dados['lng'] - (float) $pdv->lng);
            $a = sin(($lat - $latPdv) / 2) ** 2 + cos($lat) * cos($latPdv) * sin($deltaLng / 2) ** 2;
            $distancia = 6371000 * 2 * asin(sqrt(max(0, min(1, $a))));

            if ($distancia > $pdv->raio_m) {
                throw ValidationException::withMessages(['lat' => 'Check-in fora do raio permitido do PDV.']);
            }

            $registro->check_in = [
                'lat' => (float) $dados['lat'],
                'lng' => (float) $dados['lng'],
                'distancia_m' => round($distancia, 2),
                'raio_m' => $pdv->raio_m,
                'gps_em' => $gps->toIso8601String(),
                'aparelho_em' => Carbon::parse($dados['aparelho_em'])->toIso8601String(),
                'recebido_em' => now()->toIso8601String(),
                'relogio_divergente' => abs($gps->diffInSeconds(Carbon::parse($dados['aparelho_em']))) > 300,
            ];
            $registro->status = StatusVisita::EmAndamento;
            $registro->save();
            if ($sincronizacaoTardia) {
                app(AlertasOperacao::class)->emitir('sincronizacao_tardia', $registro, 'Check-in recebido após encerramento', $registro->pdv->nome.' · A visita voltou a ficar em andamento após sincronização.');
            }

            return response()->json(['id' => $registro->id, 'status' => $registro->status->value, 'check_in' => $registro->check_in], 201);
        });
    }
}
