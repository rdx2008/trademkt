<?php

namespace App\Services\Coletas;

use App\Models\ModeloColeta;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Regras do motor de coletas: normaliza a definição dos campos de um modelo (vinda do painel) e
 * valida as respostas do promotor contra a definição da versão em que ele respondeu.
 */
class Formulario
{
    private const MAX_CAMPOS = 30;

    /**
     * Definição enviada pelo painel -> lista normalizada de campos.
     *
     * @param  mixed  $entrada  lista de campos (rotulo, tipo, obrigatorio, por_sku, opcoes)
     * @return list<array{chave: string, rotulo: string, tipo: string, obrigatorio: bool, por_sku: bool, opcoes: list<string>, papel: ?string}>
     *
     * @throws ValidationException
     */
    public static function normalizarCampos(mixed $entrada): array
    {
        $entrada = array_values(array_filter(is_array($entrada) ? $entrada : [], fn ($c) => is_array($c) && trim((string) ($c['rotulo'] ?? '')) !== ''));
        if ($entrada === []) {
            throw ValidationException::withMessages(['campos' => 'Inclua pelo menos um campo no modelo.']);
        }
        if (count($entrada) > self::MAX_CAMPOS) {
            throw ValidationException::withMessages(['campos' => 'No máximo '.self::MAX_CAMPOS.' campos por modelo.']);
        }

        $campos = [];
        $usadas = [];
        foreach ($entrada as $i => $c) {
            $tipo = (string) ($c['tipo'] ?? '');
            if (! isset(ModeloColeta::TIPOS_CAMPO[$tipo])) {
                throw ValidationException::withMessages(['campos' => 'Tipo de campo inválido no campo '.($i + 1).'.']);
            }
            $rotulo = Str::limit(trim((string) $c['rotulo']), 120, '');
            $chave = Str::slug($rotulo, '_') ?: 'campo';
            $base = $chave;
            for ($n = 2; isset($usadas[$chave]); $n++) {
                $chave = $base.'_'.$n;
            }
            $usadas[$chave] = true;

            $opcoes = [];
            if ($tipo === 'escolha') {
                $texto = is_array($c['opcoes'] ?? null) ? implode("\n", $c['opcoes']) : (string) ($c['opcoes'] ?? '');
                $opcoes = array_values(array_unique(array_filter(array_map(fn ($o) => Str::limit(trim($o), 80, ''), preg_split('/\R/', $texto)))));
                if (count($opcoes) < 2) {
                    throw ValidationException::withMessages(['campos' => 'O campo "'.$rotulo.'" precisa de pelo menos duas opções (uma por linha).']);
                }
            }
            $campos[] = [
                'chave' => $chave,
                'rotulo' => $rotulo,
                'tipo' => $tipo,
                'obrigatorio' => filter_var($c['obrigatorio'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'por_sku' => filter_var($c['por_sku'] ?? false, FILTER_VALIDATE_BOOLEAN) && $tipo !== 'foto',
                'opcoes' => $opcoes,
                'papel' => isset(ModeloColeta::PAPEIS[(string) ($c['papel'] ?? '')]) ? (string) $c['papel'] : null,
            ];
        }

        return $campos;
    }

    /**
     * Valida as respostas contra os campos (usa só os campos do tipo certo: por SKU ou gerais).
     *
     * @param  list<array<string, mixed>>  $campos
     * @param  array<string, mixed>  $respostas
     * @return array<string, mixed> respostas limpas, só com as chaves do modelo
     *
     * @throws ValidationException
     */
    public static function validar(array $campos, array $respostas, bool $porSku): array
    {
        $limpas = [];
        $erros = [];
        foreach ($campos as $campo) {
            if ((bool) ($campo['por_sku'] ?? false) !== $porSku) {
                continue;
            }
            $chave = $campo['chave'];
            $valor = $respostas[$chave] ?? null;
            if ($valor === null || $valor === '') {
                if (! empty($campo['obrigatorio'])) {
                    $erros["respostas.$chave"] = 'Responda "'.$campo['rotulo'].'".';
                }

                continue;
            }
            $ok = match ($campo['tipo']) {
                'sim_nao' => is_bool($valor) || in_array($valor, [0, 1, '0', '1', 'sim', 'nao'], true),
                'numero' => is_numeric($valor),
                'quantidade' => is_numeric($valor) && (int) $valor == $valor && $valor >= 0,
                'preco' => is_numeric($valor) && $valor >= 0 && $valor < 1000000,
                'texto' => is_string($valor) && mb_strlen($valor) <= 1000,
                'escolha' => in_array($valor, $campo['opcoes'] ?? [], true),
                'data' => is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) && strtotime($valor) !== false,
                'foto' => is_string($valor) && mb_strlen($valor) <= 100, // identificador (uuid) da foto enviada à parte
                default => false,
            };
            if (! $ok) {
                $erros["respostas.$chave"] = 'Valor inválido em "'.$campo['rotulo'].'".';

                continue;
            }
            $limpas[$chave] = match ($campo['tipo']) {
                'sim_nao' => in_array($valor, [true, 1, '1', 'sim'], true),
                'numero', 'preco' => round((float) $valor, 2),
                'quantidade' => (int) $valor,
                default => $valor,
            };
        }
        if ($erros) {
            throw ValidationException::withMessages($erros);
        }

        return $limpas;
    }
}
