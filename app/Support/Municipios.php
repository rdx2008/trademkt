<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Municípios do Brasil (IBGE) com a coordenada do centro de cada um, lidos de
 * `database/data/municipios.csv` (5.571 linhas: 5.570 municípios e o Distrito Estadual de Fernando
 * de Noronha não conta à parte; o DF entra como Brasília). Serve para o promotor escolher a cidade
 * sem erro de grafia e para medir distância a partir do centro dela, sem consultar serviço externo.
 */
class Municipios
{
    /** @var array<string, array{codigo: string, nome: string, uf: string, latitude: float, longitude: float}>|null */
    private static ?array $todos = null;

    /** @var array<string, string>|null chave "uf|nome normalizado" => código */
    private static ?array $indice = null;

    /** @return array<string, array{codigo: string, nome: string, uf: string, latitude: float, longitude: float}> */
    public static function todos(): array
    {
        if (self::$todos === null) {
            self::$todos = [];
            $arquivo = database_path('data/municipios.csv');
            if (($f = fopen($arquivo, 'r')) !== false) {
                fgetcsv($f, 0, ';', '"', '');
                while (($l = fgetcsv($f, 0, ';', '"', '')) !== false) {
                    if (count($l) >= 5) {
                        self::$todos[$l[0]] = ['codigo' => $l[0], 'nome' => $l[1], 'uf' => $l[2], 'latitude' => (float) $l[3], 'longitude' => (float) $l[4]];
                    }
                }
                fclose($f);
            }
        }

        return self::$todos;
    }

    /** @return array{codigo: string, nome: string, uf: string, latitude: float, longitude: float}|null */
    public static function achar(?string $codigo): ?array
    {
        return $codigo ? (self::todos()[$codigo] ?? null) : null;
    }

    /** @return list<array{codigo: string, nome: string}> */
    public static function porUf(string $uf): array
    {
        $uf = strtoupper($uf);

        return array_values(array_map(
            fn (array $m) => ['codigo' => $m['codigo'], 'nome' => $m['nome']],
            array_filter(self::todos(), fn (array $m) => $m['uf'] === $uf),
        ));
    }

    public static function pertenceAUf(string $codigo, string $uf): bool
    {
        $m = self::achar($codigo);

        return $m !== null && $m['uf'] === strtoupper($uf);
    }

    /** Acha o código pelo nome digitado e a UF (ignora maiúsculas e acentos). */
    public static function procurar(?string $nome, ?string $uf): ?string
    {
        if (! $nome || ! $uf) {
            return null;
        }
        if (self::$indice === null) {
            self::$indice = [];
            foreach (self::todos() as $m) {
                self::$indice[$m['uf'].'|'.self::normalizar($m['nome'])] = $m['codigo'];
            }
        }

        return self::$indice[strtoupper($uf).'|'.self::normalizar($nome)] ?? null;
    }

    /** Distância em km entre os centros de dois municípios (fórmula de haversine). */
    public static function distanciaKm(string $codigoA, string $codigoB): ?float
    {
        $a = self::achar($codigoA);
        $b = self::achar($codigoB);
        if (! $a || ! $b) {
            return null;
        }
        $dLat = deg2rad($b['latitude'] - $a['latitude']);
        $dLng = deg2rad($b['longitude'] - $a['longitude']);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a['latitude'])) * cos(deg2rad($b['latitude'])) * sin($dLng / 2) ** 2;

        return 6371 * 2 * asin(min(1, sqrt($h)));
    }

    private static function normalizar(string $texto): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($texto))) ?? '');
    }
}
