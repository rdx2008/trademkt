<?php

namespace App\Jobs;

use App\Models\Exportacao;
use App\Services\Coletas\ResultadosColeta;
use App\Services\Operacao\BookDeFotos;
use App\Services\Operacao\ExcelIndicadores;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Gera uma exportação da fila de downloads dentro da instalação (o tenant volta sozinho no job) e
 * guarda o arquivo no disco privado. O link vale por 7 dias; arquivo e linha somem depois.
 */
class GerarExportacao implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $exportacaoId) {}

    public function handle(ExcelIndicadores $excel, ResultadosColeta $coletas, BookDeFotos $book): void
    {
        $e = Exportacao::find($this->exportacaoId);
        if (! $e || $e->estado !== 'pendente') {
            return;
        }
        $e->update(['estado' => 'gerando']);
        $p = $e->parametros;
        $local = tempnam(sys_get_temp_dir(), 'trade-exportacao-');

        try {
            $usuario = $e->usuarioDoEscopo();
            $f = $p['filtros'];
            if ($e->tipo === 'book') {
                $book->gravar($usuario, $f, $local);
                $nome = 'book-fotos-'.$f['inicio'].'-'.$f['fim'].'.zip';
            } elseif ($e->tipo === 'indicadores') {
                $excel->gravar($usuario, $f, $local);
                $nome = 'indicadores-'.$f['inicio'].'-'.$f['fim'].'.xlsx';
            } else {
                $relatorio = $coletas->relatorio($p['relatorio'], $usuario, $f);
                $nome = 'coletas-'.$p['relatorio'].'.csv';
                $saida = fopen($local, 'w');
                fwrite($saida, "\xEF\xBB\xBF");
                fputcsv($saida, $relatorio['colunas'], ';');
                foreach ($relatorio['linhas'] as $linha) {
                    fputcsv($saida, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, $linha), ';');
                }
                fclose($saida);
            }
            $caminho = 'exportacoes/'.$e->uuid.'-'.$nome;
            Storage::disk('local')->put($caminho, fopen($local, 'r'));
            $e->update([
                'estado' => 'pronto', 'nome' => $nome, 'arquivo' => $caminho, 'tamanho' => filesize($local),
                'pronta_em' => now(), 'expira_em' => now()->addDays(Exportacao::DIAS_DE_VALIDADE), 'erro' => null,
            ]);
        } catch (\Throwable $erro) {
            $e->update(['estado' => 'erro', 'erro' => mb_substr($erro->getMessage(), 0, 500)]);
        } finally {
            @unlink($local);
        }
    }
}
