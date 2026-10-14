<?php

namespace App\Services\Roteiros;

use App\Enums\OrigemVisita;
use App\Enums\Perfil;
use App\Enums\StatusVisita;
use App\Models\ContratoVisita;
use App\Models\Visita;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Cria as visitas previstas a partir dos contratos vigentes (uma por contrato por dia marcado).
 *
 * Idempotente: rodar de novo não duplica (a chave é contrato + data prevista, então uma visita
 * que o supervisor mudou de dia continua valendo). Só mexe de hoje em diante e só em visitas ainda
 * previstas: remove as que o contrato deixou de prever (contrato encerrado, inativo, dia
 * desmarcado, PDV inativo). A visita nova já vem com o promotor que fez esse contrato no mesmo
 * dia da semana anterior; o supervisor ajusta o que precisar.
 */
class GeradorVisitas
{
    public function __construct(private Atribuicao $atribuicao) {}

    /** @return array{criadas: int, removidas: int, atribuidas: int} */
    public function gerar(CarbonInterface $inicio, CarbonInterface $fim, ?int $agenciaId = null): array
    {
        $inicio = $inicio->copy()->startOfDay()->max(today());
        $fim = $fim->copy()->startOfDay();
        $resultado = ['criadas' => 0, 'removidas' => 0, 'atribuidas' => 0];

        if ($fim->isBefore($inicio)) {
            return $resultado;
        }

        // Contratos ativos que valem em algum dia do período, com PDV ativo.
        $contratos = ContratoVisita::query()
            ->ativos()
            ->when($agenciaId, fn ($q) => $q->where('agencia_id', $agenciaId))
            ->whereDate('inicio', '<=', $fim->toDateString())
            ->where(fn ($q) => $q->whereNull('fim')->orWhereDate('fim', '>=', $inicio->toDateString()))
            ->whereHas('pdv', fn ($q) => $q->where('ativo', true))
            ->get();

        DB::transaction(function () use ($contratos, $inicio, $fim, $agenciaId, &$resultado) {
            $previstas = [];

            foreach (CarbonPeriod::create($inicio, $fim) as $dia) {
                foreach ($contratos as $contrato) {
                    if (! $contrato->vigente($dia) || ! in_array($dia->isoWeekday(), $contrato->dias, true)) {
                        continue;
                    }

                    $visita = Visita::where('contrato_id', $contrato->id)->whereDate('data_prevista', $dia->toDateString())->first()
                        ?? new Visita([
                            'contrato_id' => $contrato->id,
                            'data_prevista' => $dia->toDateString(),
                            'data' => $dia->toDateString(),
                        ]);
                    $nova = ! $visita->exists;

                    if (! $nova && $visita->status !== StatusVisita::Previsto) {
                        $previstas[] = $visita->id;

                        continue;
                    }

                    // Contrato editado (outra agência, PDV ou indústria): a visita acompanha.
                    if (! $nova && $visita->agencia_id !== $contrato->agencia_id) {
                        $visita->roteiro_id = null;
                    }

                    $visita->fill([
                        'agencia_id' => $contrato->agencia_id,
                        'pdv_id' => $contrato->pdv_id,
                        'industria_id' => $contrato->industria_id,
                        'origem' => OrigemVisita::Contrato,
                    ]);
                    if ($nova) {
                        $visita->status = StatusVisita::Previsto;
                        $visita->ordem = 0;
                    }
                    $visita->save();
                    $previstas[] = $visita->id;

                    if ($nova) {
                        $resultado['criadas']++;
                        $resultado['atribuidas'] += (int) $this->repetirPromotorDaSemanaAnterior($visita);
                    }
                }
            }

            $resultado['removidas'] = Visita::query()
                ->where('origem', OrigemVisita::Contrato->value)
                ->where('status', StatusVisita::Previsto->value)
                ->whereDate('data_prevista', '>=', $inicio->toDateString())
                ->whereDate('data_prevista', '<=', $fim->toDateString())
                ->when($agenciaId, fn ($q) => $q->where('agencia_id', $agenciaId))
                ->whereNotIn('id', $previstas)
                ->delete();
        });

        return $resultado;
    }

    private function repetirPromotorDaSemanaAnterior(Visita $visita): bool
    {
        $anterior = Visita::query()
            ->with('roteiro.promotor')
            ->where('contrato_id', $visita->contrato_id)
            ->whereDate('data_prevista', $visita->data_prevista->copy()->subWeek()->toDateString())
            ->whereNotNull('roteiro_id')
            ->first();

        $promotor = $anterior?->roteiro?->promotor;

        if (! $promotor || ! $promotor->ativo || $promotor->perfil !== Perfil::Promotor || $promotor->agencia_id !== $visita->agencia_id) {
            return false;
        }

        $this->atribuicao->mover($visita, $promotor, $visita->data, $anterior->roteiro->supervisor);

        return true;
    }
}
