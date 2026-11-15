<?php

namespace App\Console\Commands;

use App\Models\Exportacao;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ExportacoesLimpar extends Command
{
    protected $signature = 'exportacoes:limpar';

    protected $description = 'Apaga as exportações da fila de downloads que passaram da validade (arquivo e registro), em todas as instalações';

    public function handle(): int
    {
        $total = 0;
        foreach (Tenant::cursor() as $tenant) {
            try {
                $total += $tenant->run(function () {
                    $vencidas = Exportacao::whereNotNull('expira_em')->where('expira_em', '<', now())->get();
                    foreach ($vencidas as $e) {
                        if ($e->arquivo) {
                            Storage::disk('local')->delete($e->arquivo);
                        }
                        $e->delete();
                    }

                    return $vencidas->count();
                });
            } catch (\Throwable $e) {
                report($e);
                $this->error('Falha ao limpar '.$tenant->id);
            }
        }
        $this->info($total.' exportação(ões) vencida(s) removida(s).');

        return self::SUCCESS;
    }
}
