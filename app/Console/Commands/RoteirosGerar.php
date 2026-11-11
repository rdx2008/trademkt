<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Roteiros\GeradorVisitas;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Gera as visitas previstas dos contratos para a semana atual e a seguinte, em todos os clientes ativos.
 * Agendado diariamente (routes/console.php); pode rodar à mão sem duplicar nada.
 */
class RoteirosGerar extends Command
{
    protected $signature = 'roteiros:gerar
        {--cliente= : Só este cliente (id)}
        {--semanas=2 : Quantas semanas a partir da atual}';

    protected $description = 'Gera as visitas previstas a partir dos contratos de visita';

    public function handle(GeradorVisitas $gerador): int
    {
        $semanas = max(1, (int) $this->option('semanas'));
        $inicio = Carbon::today()->startOfWeek(Carbon::MONDAY);
        $fim = $inicio->copy()->addWeeks($semanas)->subDay();

        $clientes = Tenant::query()
            ->where('ativo', true)
            ->when($this->option('cliente'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($clientes as $cliente) {
            $resultado = $cliente->run(fn () => $gerador->gerar($inicio, $fim));

            $this->line(sprintf(
                '%s: %d criada(s), %d atribuída(s) ao promotor da semana anterior, %d removida(s).',
                $cliente->id, $resultado['criadas'], $resultado['atribuidas'], $resultado['removidas']
            ));
        }

        return self::SUCCESS;
    }
}
