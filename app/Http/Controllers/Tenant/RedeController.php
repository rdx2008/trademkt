<?php

namespace App\Http\Controllers\Tenant;

use App\Models\Rede;

class RedeController extends CadastroSimplesController
{
    protected function modelo(): string
    {
        return Rede::class;
    }

    protected function rota(): string
    {
        return 'redes';
    }

    protected function rotulos(): array
    {
        return ['singular' => 'Rede de supermercados', 'plural' => 'Redes de supermercados', 'novo' => 'Nova rede de supermercados', 'feminino' => true];
    }
}
