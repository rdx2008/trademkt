<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * GTIN (EAN-8, UPC-A/GTIN-12, EAN-13, GTIN-14).
 *
 * A regra sempre recusa o que não é GTIN (letras, tamanho errado). Dígito verificador errado
 * só é recusado com $exigirDigito = true; no cadastro ele é aceito e marcado para revisão,
 * porque catálogos de indústria às vezes trazem GTIN com erro de digitação.
 */
class Gtin implements ValidationRule
{
    public const TAMANHOS = [8, 12, 13, 14];

    public function __construct(private bool $exigirDigito = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $gtin = self::normalizar((string) $value);

        if (! self::formatoValido($gtin)) {
            $fail('O :attribute deve ter 8, 12, 13 ou 14 dígitos.');

            return;
        }

        if ($this->exigirDigito && ! self::digitoCorreto($gtin)) {
            $fail('O :attribute tem o dígito verificador errado.');
        }
    }

    /** Remove espaços, pontos e traços. */
    public static function normalizar(?string $gtin): string
    {
        return preg_replace('/[\s.\-]/', '', trim((string) $gtin));
    }

    public static function formatoValido(?string $gtin): bool
    {
        $gtin = self::normalizar($gtin);

        return ctype_digit($gtin) && in_array(strlen($gtin), self::TAMANHOS, true);
    }

    /** Dígito verificador GS1 (módulo 10, pesos 3 e 1 da direita para a esquerda). */
    public static function digitoCorreto(?string $gtin): bool
    {
        $gtin = self::normalizar($gtin);

        if (! self::formatoValido($gtin)) {
            return false;
        }

        $corpo = strrev(substr($gtin, 0, -1));
        $soma = 0;
        for ($i = 0, $n = strlen($corpo); $i < $n; $i++) {
            $soma += (int) $corpo[$i] * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $soma % 10) % 10 === (int) substr($gtin, -1);
    }

    public static function valido(?string $gtin): bool
    {
        return self::formatoValido($gtin) && self::digitoCorreto($gtin);
    }
}
