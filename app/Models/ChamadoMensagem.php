<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class ChamadoMensagem extends Model
{
    use CentralConnection;

    public const UPDATED_AT = null;

    protected $table = 'chamado_mensagens';

    protected $guarded = ['id'];
}
