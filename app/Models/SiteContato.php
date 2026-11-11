<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class SiteContato extends Model
{
    use CentralConnection;

    protected $table = 'site_contatos';

    protected $fillable = ['nome', 'email', 'empresa', 'perfil', 'mensagem', 'situacao'];
}
