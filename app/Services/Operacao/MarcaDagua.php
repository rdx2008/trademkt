<?php

namespace App\Services\Operacao;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarcaDagua
{
    public function gravar(UploadedFile $arquivo, string $caminho, array $linhas): array
    {
        // O prefixo também isola clientes em discos remotos compartilhados.
        $caminho = 'clientes/'.tenant('id').'/'.$caminho;
        $tamanho = @getimagesize($arquivo->getRealPath());
        if (! $tamanho || $tamanho[0] * $tamanho[1] > 8000000) {
            throw ValidationException::withMessages(['foto' => 'Use imagem JPEG/PNG de até 8 milhões de pixels.']);
        }
        $origem = @imagecreatefromstring(file_get_contents($arquivo->getRealPath()));
        if (! $origem) {
            throw ValidationException::withMessages(['foto' => 'Imagem inválida.']);
        }
        $escala = min(1, 2000 / max($tamanho[0], $tamanho[1]));
        $w = max(1, (int) round($tamanho[0] * $escala));
        $h = max(1, (int) round($tamanho[1] * $escala));
        $imagem = imagecreatetruecolor(max(640, $w), $h + 90);
        try {
            imagefill($imagem, 0, 0, imagecolorallocate($imagem, 255, 255, 255));
            imagecopyresampled($imagem, $origem, (int) ((imagesx($imagem) - $w) / 2), 0, 0, 0, $w, $h, $tamanho[0], $tamanho[1]);
            $preto = imagecolorallocate($imagem, 20, 25, 30);
            foreach (array_slice($linhas, 0, 5) as $i => $linha) {
                imagestring($imagem, 3, 10, $h + 6 + $i * 16, substr(Str::ascii($linha), 0, 88), $preto);
            }
            ob_start();
            try {
                imagejpeg($imagem, null, 85);
                $jpeg = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $disco = config('trade.fotos_disk', 'local');
            if (! Storage::disk($disco)->put($caminho, $jpeg, ['visibility' => 'private'])) {
                throw new \RuntimeException('Não foi possível armazenar a foto.');
            }

            return ['caminho' => $caminho, 'disco' => $disco];
        } finally {
            imagedestroy($origem);
            imagedestroy($imagem);
        }
    }
}
