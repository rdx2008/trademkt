<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class SiteInstitucional extends Model
{
    use CentralConnection;

    protected $table = 'site_institucional';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rascunho' => 'array', 'publicado' => 'array', 'publicado_em' => 'datetime'];
    }
}
