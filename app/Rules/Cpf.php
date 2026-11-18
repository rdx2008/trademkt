<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CPF com 11 dígitos e dígitos verificadores corretos. Aceita com ou sem máscara.
 */
class Cpf implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::valido((string) $value)) {
            $fail('O :attribute informado não é um CPF válido.');
        }
    }

    public static function limpar(?string $cpf): string
    {
        return preg_replace('/\D/', '', (string) $cpf);
    }

    public static function valido(?string $cpf): bool
    {
        $cpf = self::limpar($cpf);

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        foreach ([9, 10] as $posicao) {
            $soma = 0;
            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $cpf[$i] * ($posicao + 1 - $i);
            }
            $digito = ($soma * 10) % 11 % 10;
            if ((int) $cpf[$posicao] !== $digito) {
                return false;
            }
        }

        return true;
    }

    /** 123.456.789-09 */
    public static function formatar(?string $cpf): string
    {
        $c = self::limpar($cpf);

        return strlen($c) === 11 ? substr($c, 0, 3).'.'.substr($c, 3, 3).'.'.substr($c, 6, 3).'-'.substr($c, 9) : (string) $cpf;
    }

    /** ***.456.789-** : o suficiente para conferir, sem expor o número. */
    public static function mascarar(?string $cpf): string
    {
        $c = self::limpar($cpf);

        return strlen($c) === 11 ? '***.'.substr($c, 3, 3).'.'.substr($c, 6, 3).'-**' : '—';
    }

    /** Impressão do CPF para busca de duplicidade (o CPF em si fica criptografado). */
    public static function hash(string $cpf): string
    {
        return hash_hmac('sha256', self::limpar($cpf), (string) config('app.key'));
    }
}
