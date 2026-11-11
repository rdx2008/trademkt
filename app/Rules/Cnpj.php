<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CNPJ com 14 dígitos e dígitos verificadores corretos. Aceita com ou sem máscara.
 */
class Cnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::valido((string) $value)) {
            $fail('O :attribute informado não é um CNPJ válido.');
        }
    }

    public static function limpar(?string $cnpj): string
    {
        return preg_replace('/\D/', '', (string) $cnpj);
    }

    public static function valido(?string $cnpj): bool
    {
        $cnpj = self::limpar($cnpj);

        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        foreach ([12, 13] as $posicao) {
            $pesos = $posicao === 12
                ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]
                : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

            $soma = 0;
            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $cnpj[$i] * $pesos[$i];
            }

            $resto = $soma % 11;
            $digito = $resto < 2 ? 0 : 11 - $resto;

            if ((int) $cnpj[$posicao] !== $digito) {
                return false;
            }
        }

        return true;
    }

    public static function formatar(?string $cnpj): string
    {
        $cnpj = self::limpar($cnpj);

        return strlen($cnpj) === 14
            ? vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($cnpj))
            : (string) $cnpj;
    }
}
