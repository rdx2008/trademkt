<?php

namespace App\Services\Geocoding;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Endereço → coordenadas. Com `GOOGLE_MAPS_API_KEY`, usa a API de Geocoding do Google; sem
 * chave, usa o OpenStreetMap (Nominatim), que é gratuito e atende o cadastro de PDVs (poucas
 * consultas, uma por segundo no máximo). Usado só no cadastro do PDV, com cache: o mesmo
 * endereço não é consultado de novo.
 */
class Geocodificador
{
    private const URL = 'https://maps.googleapis.com/maps/api/geocode/json';

    private const URL_OSM = 'https://nominatim.openstreetmap.org/search';

    public function disponivel(): bool
    {
        return filled(config('trade.geocoding.google_key')) || (bool) config('trade.geocoding.osm');
    }

    /**
     * @param  string|list<string>  $endereco  um endereço ou variações, da mais precisa à mais geral
     * @return array{lat: float, lng: float}|null
     */
    public function coordenadas(string|array $endereco): ?array
    {
        foreach ((array) $endereco as $tentativa) {
            $resultado = $this->uma($tentativa);
            if ($resultado) {
                return $resultado;
            }
        }

        return null;
    }

    /** @return array{lat: float, lng: float}|null */
    private function uma(string $endereco): ?array
    {
        $endereco = trim(preg_replace('/\s+/', ' ', $endereco));

        if ($endereco === '' || ! $this->disponivel()) {
            return null;
        }

        $chave = 'geocoding:'.sha1(mb_strtolower($endereco));

        if (Cache::has($chave)) {
            return Cache::get($chave);
        }

        $resultado = filled(config('trade.geocoding.google_key')) ? $this->consultar($endereco) : $this->consultarOsm($endereco);

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

    /** @return array{lat: float, lng: float}|null|false false = falha temporária */
    private function consultarOsm(string $endereco): array|null|false
    {
        $this->respeitarLimiteOsm();

        try {
            $resposta = Http::timeout(10)
                ->withHeaders(['User-Agent' => config('app.name', 'Trade Promo').' (cadastro de PDVs)'])
                ->acceptJson()
                ->get(self::URL_OSM, ['q' => $endereco, 'format' => 'jsonv2', 'limit' => 1, 'countrycodes' => 'br', 'accept-language' => 'pt-BR']);
        } catch (ConnectionException $e) {
            Log::warning('Geocoding (OpenStreetMap) indisponível', ['erro' => $e->getMessage()]);

            return false;
        }

        if (! $resposta->successful()) {
            Log::warning('Geocoding (OpenStreetMap) falhou', ['http' => $resposta->status()]);

            return false;
        }

        $achado = $resposta->json('0');
        if (! is_array($achado) || ! isset($achado['lat'], $achado['lon'])) {
            return null;
        }

        return ['lat' => round((float) $achado['lat'], 7), 'lng' => round((float) $achado['lon'], 7)];
    }

    /** O OpenStreetMap pede no máximo uma consulta por segundo. */
    private function respeitarLimiteOsm(): void
    {
        $ultima = (float) Cache::get('geocoding:osm:ultima', 0);
        $espera = (int) ceil((1.1 - (microtime(true) - $ultima)) * 1_000_000);
        if ($espera > 0 && $espera < 1_200_000) {
            usleep($espera);
        }
        Cache::put('geocoding:osm:ultima', microtime(true), 60);
    }
}
