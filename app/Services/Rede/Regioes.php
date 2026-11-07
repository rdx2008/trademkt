<?php

namespace App\Services\Rede;

use App\Support\Estados;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Lista de regiões (UF e município opcional) de um vínculo indústria–agência. */
class Regioes
{
    public const MAXIMO = 30;

    /**
     * Valida e normaliza a lista. Linhas sem UF são ignoradas; lista vazia vira null
     * (todas as regiões).
     *
     * @return ?list<array{uf: string, cidade: ?string}>
     *
     * @throws ValidationException
     */
    public static function normalizar(mixed $regioes, string $campo = 'regioes'): ?array
    {
        $regioes = array_values(array_filter(is_array($regioes) ? $regioes : [], fn ($r) => is_array($r) && trim((string) ($r['uf'] ?? '')) !== ''));
        if ($regioes === []) {
            return null;
        }
        Validator::make([$campo => $regioes], [
            $campo => ['array', 'max:'.self::MAXIMO],
            $campo.'.*.uf' => ['required', Rule::in(Estados::UFS)],
            $campo.'.*.cidade' => ['nullable', 'string', 'max:100'],
        ], [
            $campo.'.max' => 'Informe no máximo '.self::MAXIMO.' regiões.',
            $campo.'.*.uf.in' => 'Estado inválido.',
        ])->validate();

        $resultado = [];
        $chaves = [];
        foreach ($regioes as $regiao) {
            $cidade = trim((string) ($regiao['cidade'] ?? '')) ?: null;
            $chave = $regiao['uf'].':'.Str::lower(Str::ascii($cidade ?? ''));
            if (isset($chaves[$chave])) {
                throw ValidationException::withMessages([$campo => 'Não repita a mesma região. Cada linha deve indicar um estado ou município diferente.']);
            }
            $chaves[$chave] = true;
            $resultado[] = ['uf' => $regiao['uf'], 'cidade' => $cidade];
        }

        return $resultado;
    }

    /** @param  list<array{uf: string, cidade: ?string}>  $regioes */
    public static function rotulo(array $regioes): string
    {
        return implode('; ', array_map(fn ($r) => ! empty($r['cidade']) ? $r['cidade'].', '.$r['uf'] : $r['uf'], $regioes));
    }
}
