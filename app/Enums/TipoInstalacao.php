<?php

namespace App\Enums;

enum TipoInstalacao: string
{
    case Agencia = 'agencia';
    case Industria = 'industria';

    public function label(): string
    {
        return match ($this) {
            self::Agencia => 'Agência',
            self::Industria => 'Indústria',
        };
    }
}
