<?php

namespace App\Enums;

enum TipoVinculo: string
{
    case Clt = 'clt';
    case Freelancer = 'freelancer';

    public function label(): string
    {
        return match ($this) {
            self::Clt => 'CLT',
            self::Freelancer => 'Freelancer',
        };
    }
}
