<?php

namespace App\Services\Geocoding;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * CEP → endereço (e, quando a fonte informa, coordenadas). Sem chave: BrasilAPI primeiro
 * (traz coordenadas de muitos CEPs) e ViaCEP como reserva. Resultado guardado em cache.
 */
class ConsultaCep
{
    /** @return array{cep: string, logradouro: string, bairro: string, cidade: string, uf: string, lat: ?float, lng: ?float}|null */
    public function consultar(string $cep): ?array
    {
        $cep = preg_replace('/\D/', '', $cep);
        if (strlen($cep) !== 8) {
            return null;
        }

        $chave = 'cep:'.$cep;
        if (Cache::has($chave)) {
            return Cache::get($chave) ?: null;
        }

        $resultado = $this->brasilApi($cep);
        $falhou = $resultado === false;
        if (! is_array($resultado)) {
            $resultado = $this->viaCep($cep);
            $falhou = $falhou && $resultado === false;
        }

        // Só guarda resposta válida ou "não encontrado"; falha de rede tenta de novo depois.
        if (! $falhou) {
            Cache::put($chave, is_array($resultado) ? $resultado : [], now()->addDays((int) config('trade.geocoding.cache_dias')));
        }

        return is_array($resultado) ? $resultado : null;
    }

    /** @return array<string, mixed>|null|false false = falha temporária */
    private function brasilApi(string $cep): array|null|false
    {
        try {
            $r = Http::timeout(8)->acceptJson()->get("https://brasilapi.com.br/api/cep/v2/{$cep}");
        } catch (ConnectionException) {
            return false;
        }
        if ($r->status() === 404) {
            return null;
        }
        if (! $r->successful() || ! $r->json('city')) {
            return false;
        }

        $lat = $r->json('location.coordinates.latitude');
        $lng = $r->json('location.coordinates.longitude');

        return $this->montar($cep, (string) $r->json('street'), (string) $r->json('neighborhood'), (string) $r->json('city'), (string) $r->json('state'),
            is_numeric($lat) ? round((float) $lat, 7) : null, is_numeric($lng) ? round((float) $lng, 7) : null);
    }

    /** @return array<string, mixed>|null|false */
    private function viaCep(string $cep): array|null|false
    {
        try {
            $r = Http::timeout(8)->acceptJson()->get("https://viacep.com.br/ws/{$cep}/json/");
        } catch (ConnectionException) {
            return false;
        }
        if (! $r->successful()) {
            return false;
        }
        if ($r->json('erro') || ! $r->json('localidade')) {
            return null;
        }

        return $this->montar($cep, (string) $r->json('logradouro'), (string) $r->json('bairro'), (string) $r->json('localidade'), (string) $r->json('uf'), null, null);
    }

    private function montar(string $cep, string $logradouro, string $bairro, string $cidade, string $uf, ?float $lat, ?float $lng): array
    {
        return [
            'cep' => substr($cep, 0, 5).'-'.substr($cep, 5),
            'logradouro' => trim($logradouro),
            'bairro' => trim($bairro),
            'cidade' => trim($cidade),
            'uf' => strtoupper(trim($uf)),
            'lat' => $lat,
            'lng' => $lng,
        ];
    }
}
