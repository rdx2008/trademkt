<?php

namespace App\Enums;

enum OrigemVisita: string
{
    case Contrato = 'contrato';
    case Demanda = 'demanda';

    public function label(): string
    {
        return match ($this) {
            self::Contrato => 'Contrato',
            self::Demanda => 'Demanda',
        };
    }
}
