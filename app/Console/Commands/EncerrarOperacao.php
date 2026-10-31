<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Operacao\EncerrarDia;
use Illuminate\Console\Command;

class EncerrarOperacao extends Command
{
    protected $signature = 'operacao:encerrar-dia';

    protected $description = 'Marca visitas não executadas e notifica demandas vencidas de todas as instalações ativas';

    public function handle(): int
    {
        $falhou = false;
        foreach (Tenant::where('ativo', true)->cursor() as $tenant) {
            try {
                $totais = $tenant->run(fn () => app(EncerrarDia::class)->executar());
                $this->info($tenant->id.': '.$totais['visitas'].' visitas, '.$totais['demandas'].' demandas.');
            } catch (\Throwable $e) {
                report($e);
                $this->error('Falha no encerramento de '.$tenant->id);
                $falhou = true;
            }
        }

        return $falhou ? self::FAILURE : self::SUCCESS;
    }
}
