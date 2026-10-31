<?php

namespace App\Models\Rede;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class AgenciaRegiao extends Model
{
    use CentralConnection;

    protected $table = 'agencia_regioes';

    public $timestamps = false;

    protected $guarded = ['id'];
}
