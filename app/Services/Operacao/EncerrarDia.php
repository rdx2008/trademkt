<?php

namespace App\Services\Operacao;

use App\Enums\StatusVisita;
use App\Models\Demanda;
use App\Models\Visita;
use Illuminate\Support\Facades\DB;

class EncerrarDia
{
    public function executar(): array
    {
        $contagem = ['visitas' => 0, 'demandas' => 0];
        Visita::where('status', StatusVisita::Previsto->value)->whereNull('check_in')->whereDate('data', '<', today())->select('id')->chunkById(100, function ($visitas) use (&$contagem) {
            foreach ($visitas as $item) {
                DB::connection('tenant')->transaction(function () use ($item, &$contagem) {
                    $v = Visita::lockForUpdate()->find($item->id);
                    if (! $v || $v->status !== StatusVisita::Previsto || $v->check_in) {
                        return;
                    }
                    $v->status = StatusVisita::NaoExecutada;
                    $v->save();
                    app(AlertasOperacao::class)->emitir('visita_nao_executada', $v, 'Visita não executada', $v->pdv->nome.' · Prevista para '.$v->data->format('d/m/Y'));
                    $contagem['visitas']++;
                });
            }
        });
        Demanda::whereIn('status', ['aberta', 'aprovada'])->where('prazo_em', '<', now())->whereNull('atraso_notificado_em')->select('id')->chunkById(100, function ($demandas) use (&$contagem) {
            foreach ($demandas as $item) {
                DB::connection('tenant')->transaction(function () use ($item, &$contagem) {
                    $d = Demanda::lockForUpdate()->find($item->id);
                    if (! $d || ! in_array($d->status, ['aberta', 'aprovada']) || $d->atraso_notificado_em) {
                        return;
                    }
                    $d->update(['atraso_notificado_em' => now()]);
                    app(AlertasOperacao::class)->emitir('demanda_atrasada', $d, 'Demanda fora do prazo', $d->pdv->nome.' · Prazo '.$d->prazo_em->format('d/m/Y H:i'));
                    $contagem['demandas']++;
                });
            }
        });

        return $contagem;
    }
}
