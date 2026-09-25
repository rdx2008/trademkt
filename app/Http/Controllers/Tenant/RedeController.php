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
        return ['singular' => 'Rede', 'plural' => 'Redes', 'novo' => 'Nova rede', 'feminino' => true];
    }
}
