<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

class ClienteListar extends Command
{
    protected $signature = 'cliente:listar';

    protected $description = 'Lista os clientes e seus domínios';

    public function handle(): int
    {
        $linhas = Tenant::with('domains')->orderBy('id')->get()->map(fn (Tenant $t) => [
            $t->id,
            $t->nome,
            $t->tipo->label(),
            $t->codigo,
            $t->ativo ? 'sim' : 'não',
            $t->domains->pluck('domain')->implode(', '),
        ]);

        $this->table(['ID', 'Nome', 'Tipo', 'Código', 'Ativo', 'Domínios'], $linhas);

        return self::SUCCESS;
    }
}
