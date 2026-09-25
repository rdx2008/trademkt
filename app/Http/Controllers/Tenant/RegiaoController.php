<?php

namespace App\Http\Controllers\Tenant;

use App\Models\Regiao;

class RegiaoController extends CadastroSimplesController
{
    protected function modelo(): string
    {
        return Regiao::class;
    }

    protected function rota(): string
    {
        return 'regioes';
    }

    protected function rotulos(): array
    {
        return ['singular' => 'Região', 'plural' => 'Regiões', 'novo' => 'Nova região', 'feminino' => true];
    }
}
