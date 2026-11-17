<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** Aceite do promotor a um termo, por finalidade e versão do texto (banco central). */
class ConsentimentoPromotor extends Model
{
    use CentralConnection;

    protected $table = 'consentimentos_promotor';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['aceito_em' => 'datetime', 'revogado_em' => 'datetime'];
    }
}
