<?php

namespace App\Services\Operacao;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Book de fotos: as fotos das visitas do período (e dos filtros da galeria) num ZIP organizado por
 * loja, com um índice. Só entram fotos que o usuário já pode ver. Limite de fotos por book para não
 * estourar disco nem memória; passando disso, o índice avisa para refinar os filtros.
 */
class BookDeFotos
{
    public const LIMITE = 400;

    public function __construct(private Indicadores $s) {}

    /**
     * @param  array<string, mixed>  $f  filtros já validados
     * @return array{fotos: int, cortadas: bool}
     */
    public function gravar(User $u, array $f, string $arquivo): array
    {
        $zip = new ZipArchive;
        if ($zip->open($arquivo, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Não foi possível criar o arquivo ZIP.');
        }
        $indice = [['Loja', 'Cidade', 'Dia', 'Tipo', 'Indústria', 'Arquivo', 'Nota média']];
        $total = 0;
        $cortadas = false;

        $visitas = $this->s->visitas($u, $f)->whereHas('fotos')
            ->whereDate('data', '>=', $f['inicio'])->whereDate('data', '<=', $f['fim'])
            ->with(['fotos.avaliacoes', 'pdv', 'industria'])->orderBy('data')->orderBy('id');
        foreach ($visitas->lazyById(50) as $v) {
            foreach ($v->fotos as $i => $foto) {
                if ($total >= self::LIMITE) {
                    $cortadas = true;

                    break 2;
                }
                $disco = Storage::disk($foto->disco);
                if (! $disco->exists($foto->caminho)) {
                    continue;
                }
                $loja = Str::slug($v->pdv->nome) ?: 'loja-'.$v->pdv_id;
                $nome = $loja.'/'.$v->data->format('Y-m-d').'-v'.$v->id.'-'.$foto->tipo.'-'.($i + 1).'.jpg';
                $zip->addFromString($nome, (string) $disco->get($foto->caminho));
                $indice[] = [$v->pdv->nome, $v->pdv->cidade, $v->data->format('d/m/Y'), $foto->tipo, $v->industria->nome, $nome, $foto->notaMedia()];
                $total++;
            }
        }

        $csv = fopen('php://temp', 'w+');
        fwrite($csv, "\xEF\xBB\xBF");
        foreach ($indice as $linha) {
            fputcsv($csv, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, $linha), ';');
        }
        rewind($csv);
        $zip->addFromString('indice.csv', (string) stream_get_contents($csv));
        $zip->addFromString('LEIA-ME.txt', 'Book de fotos de '.$f['inicio'].' a '.$f['fim'].'. '.$total.' foto(s).'
            .($cortadas ? ' O book foi cortado em '.self::LIMITE.' fotos: refine os filtros (período, loja, rede) para gerar o restante.' : ''));
        $zip->close();

        return ['fotos' => $total, 'cortadas' => $cortadas];
    }
}
