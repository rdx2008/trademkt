<?php

namespace App\Services\Geocoding;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Endereço → coordenadas pela API de Geocoding do Google.
 * Usado só no cadastro do PDV, com cache (o mesmo endereço não é consultado de novo).
 * Sem chave configurada, não consulta nada e o cadastro segue com ajuste manual no mapa.
 */
class Geocodificador
{
    private const URL = 'https://maps.googleapis.com/maps/api/geocode/json';

    public function disponivel(): bool
    {
        return filled(config('trade.geocoding.google_key'));
    }

    /** @return array{lat: float, lng: float}|null */
    public function coordenadas(string $endereco): ?array
    {
        $endereco = trim(preg_replace('/\s+/', ' ', $endereco));

        if ($endereco === '' || ! $this->disponivel()) {
            return null;
        }

        $chave = 'geocoding:'.sha1(mb_strtolower($endereco));

        if (Cache::has($chave)) {
            return Cache::get($chave);
        }

        $resultado = $this->consultar($endereco);

        // Só guarda resposta válida ou "não encontrado"; erro de rede tenta de novo depois.
        if ($resultado !== false) {
            Cache::put($chave, $resultado, now()->addDays((int) config('trade.geocoding.cache_dias')));
        }

        return $resultado ?: null;
    }

    /** @return array{lat: float, lng: float}|null|false false = falha temporária */
    private function consultar(string $endereco): array|null|false
    {
        try {
            $resposta = Http::timeout(10)->get(self::URL, [
                'address' => $endereco,
                'region' => 'br',
                'language' => 'pt-BR',
                'key' => config('trade.geocoding.google_key'),
            ]);
        } catch (ConnectionException $e) {
            Log::warning('Geocoding indisponível', ['erro' => $e->getMessage()]);

            return false;
        }

        $status = $resposta->json('status');

        if ($status === 'ZERO_RESULTS') {
            return null;
        }

        if (! $resposta->successful() || $status !== 'OK') {
            Log::warning('Geocoding falhou', ['status' => $status, 'http' => $resposta->status()]);

            return false;
        }

        $local = $resposta->json('results.0.geometry.location');

        return ['lat' => round((float) $local['lat'], 7), 'lng' => round((float) $local['lng'], 7)];
    }
}
