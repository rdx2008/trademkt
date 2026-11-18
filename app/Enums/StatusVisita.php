<?php

namespace App\Enums;

enum StatusVisita: string
{
    case Previsto = 'previsto';
    case EmAndamento = 'em_andamento';
    case Realizada = 'realizada';
    case EmAtencao = 'em_atencao';
    case NaoExecutada = 'nao_executada';

    public function label(): string
    {
        return match ($this) {
            self::Previsto => 'Prevista',
            self::EmAndamento => 'Em andamento',
            self::Realizada => 'Realizada',
            self::EmAtencao => 'Em atenção',
            self::NaoExecutada => 'Não executada',
        };
    }
}
