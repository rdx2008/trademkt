<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** Identidade do promotor no banco central (uma pessoa, várias agências). */
class PromotorGlobal extends Model
{
    use CentralConnection;

    protected $table = 'promotores_globais';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['disponivel_rede' => 'boolean'];
    }
}
