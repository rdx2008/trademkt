<?php

namespace App\Enums;

enum TipoEmbalagem: string
{
    case Unidade = 'unidade';
    case Reembalagem = 'reembalagem';
    case CaixaMaster = 'caixa_master';

    public function label(): string
    {
        return match ($this) {
            self::Unidade => 'Unidade',
            self::Reembalagem => 'Reembalagem',
            self::CaixaMaster => 'Caixa master',
        };
    }

    /** @return array<string, string> valor => rótulo */
    public static function opcoes(): array
    {
        $opcoes = [];
        foreach (self::cases() as $tipo) {
            $opcoes[$tipo->value] = $tipo->label();
        }

        return $opcoes;
    }
}
