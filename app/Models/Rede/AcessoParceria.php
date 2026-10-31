<?php

namespace App\Models\Rede;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class AcessoParceria extends Model
{
    use CentralConnection;

    protected $table = 'acessos_parceria';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expira_em' => 'datetime'];
    }
}
