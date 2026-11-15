<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * APK do app do promotor, distribuído por link na página do sistema (sem loja de aplicativos).
 * Fica no disco privado (`aplicativo/promotor.apk`) com os dados da versão ao lado; o download
 * público passa por rota própria. Só há uma versão publicada por vez.
 */
class AplicativoAndroid
{
    private const ARQUIVO = 'aplicativo/promotor.apk';

    private const INFO = 'aplicativo/info.json';

    /** @return array{versao: string, enviado_em: string, tamanho: int, sha256: string}|null */
    public function atual(): ?array
    {
        $disco = Storage::disk('local');
        if (! $disco->exists(self::ARQUIVO) || ! $disco->exists(self::INFO)) {
            return null;
        }
        $info = json_decode((string) $disco->get(self::INFO), true);

        return is_array($info) ? $info : null;
    }

    public function caminho(): string
    {
        return Storage::disk('local')->path(self::ARQUIVO);
    }

    /** O arquivo é mesmo um APK? (zip com AndroidManifest.xml dentro) */
    public static function pareceApk(string $caminho): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($caminho) !== true) {
            return false;
        }
        $ok = $zip->locateName('AndroidManifest.xml') !== false;
        $zip->close();

        return $ok;
    }

    public function publicar(UploadedFile $arquivo, string $versao): array
    {
        $disco = Storage::disk('local');
        $disco->putFileAs('aplicativo', $arquivo, 'promotor.apk');
        $info = [
            'versao' => $versao,
            'enviado_em' => now()->toIso8601String(),
            'tamanho' => (int) $disco->size(self::ARQUIVO),
            'sha256' => hash_file('sha256', $disco->path(self::ARQUIVO)),
        ];
        $disco->put(self::INFO, json_encode($info, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $info;
    }

    public function remover(): void
    {
        Storage::disk('local')->delete([self::ARQUIVO, self::INFO]);
    }
}
