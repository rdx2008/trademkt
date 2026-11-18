<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Definição dos campos de uma versão de um modelo de coleta. */
class ModeloColetaVersao extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'modelo_coleta_versoes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['campos' => 'array', 'versao' => 'integer'];
    }
}
