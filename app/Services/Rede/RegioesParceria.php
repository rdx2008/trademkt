<?php

namespace App\Services\Rede;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RegioesParceria
{
    public static function normalizar(mixed $regioes, string $campo = 'regioes'): array
    {
        Validator::make([$campo => $regioes], [
            $campo => ['required', 'array', 'min:1', 'max:30'],
            $campo.'.*' => ['required', 'array:uf,cidade'],
            $campo.'.*.uf' => ['required', Rule::in(PerfilAgencia::UFS)],
            $campo.'.*.cidade' => ['nullable', 'string', 'max:100'],
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

    public static function doRequest(Request $request): array
    {
        if ($request->exists('regioes')) {
            return self::normalizar($request->input('regioes'));
        }
        // Mantém compatibilidade com os formulários publicados na versão anterior.
        $dados = $request->validate(['uf' => ['required', Rule::in(PerfilAgencia::UFS)], 'cidade' => ['nullable', 'string', 'max:100']]);

        return self::normalizar([['uf' => $dados['uf'], 'cidade' => $dados['cidade'] ?? null]]);
    }

    public static function rotulo(array $regioes): string
    {
        return implode('; ', array_map(fn ($r) => ! empty($r['cidade']) ? $r['cidade'].', '.$r['uf'] : $r['uf'], $regioes));
    }

    public static function resumo(array $regioes): string
    {
        $rotulo = self::rotulo($regioes);

        return mb_strlen($rotulo, 'UTF-8') > 200 ? mb_substr($rotulo, 0, 199, 'UTF-8').'…' : $rotulo;
    }
}
