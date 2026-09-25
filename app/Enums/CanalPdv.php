<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum CanalPdv: string
{
    case Supermercado = 'supermercado';
    case Hipermercado = 'hipermercado';
    case Atacarejo = 'atacarejo';
    case Atacado = 'atacado';
    case Farmacia = 'farmacia';
    case Conveniencia = 'conveniencia';
    case Outro = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::Supermercado => 'Supermercado',
            self::Hipermercado => 'Hipermercado',
            self::Atacarejo => 'Atacarejo',
            self::Atacado => 'Atacado',
            self::Farmacia => 'Farmácia',
            self::Conveniencia => 'Conveniência',
            self::Outro => 'Outro',
        };
    }

    /** Aceita o valor ou o rótulo (com ou sem acento), como vem de planilha. */
    public static function deTexto(?string $texto): ?self
    {
        $texto = trim((string) $texto);
        if ($texto === '') {
            return null;
        }

        $chave = Str::of($texto)->ascii()->lower()->toString();

        return self::tryFrom($chave);
    }

    /** @return array<string, string> valor => rótulo */
    public static function opcoes(): array
    {
        $opcoes = [];
        foreach (self::cases() as $canal) {
            $opcoes[$canal->value] = $canal->label();
        }

        return $opcoes;
    }
}
