<?php

namespace App\Services\Operacao;

use App\Models\Pdv;
use Illuminate\Validation\ValidationException;

class Geo
{
    public static function distancia(Pdv $pdv, float $lat, float $lng): float
    {
        if ($pdv->lat === null || $pdv->lng === null) {
            throw ValidationException::withMessages(['lat' => 'O PDV não possui coordenadas.']);
        }
        $a = sin(deg2rad($lat - $pdv->lat) / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($pdv->lat)) * sin(deg2rad($lng - $pdv->lng) / 2) ** 2;
        $metros = 6371000 * 2 * asin(sqrt(max(0, min(1, $a))));
        if ($metros > $pdv->raio_m) {
            throw ValidationException::withMessages(['lat' => 'Localização fora do raio permitido do PDV.']);
        }

        return round($metros, 2);
    }
}
