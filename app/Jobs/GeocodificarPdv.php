<?php

namespace App\Jobs;

use App\Models\Pdv;
use App\Services\Geocoding\Geocodificador;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Busca as coordenadas de um PDV sem lat/lng (usado na importação por planilha).
 */
class GeocodificarPdv implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $pdvId) {}

    public function handle(Geocodificador $geocodificador): void
    {
        $pdv = Pdv::find($this->pdvId);

        if (! $pdv || $pdv->temCoordenadas()) {
            return;
        }

        $coordenadas = $geocodificador->coordenadas($pdv->enderecoCompleto());

        if ($coordenadas) {
            $pdv->forceFill($coordenadas + ['coordenadas_origem' => 'geocoding'])->save();
        }
    }
}
