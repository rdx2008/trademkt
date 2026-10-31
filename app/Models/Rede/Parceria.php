<?php

namespace App\Models\Rede;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class Parceria extends Model
{
    use CentralConnection;

    protected $table = 'parcerias';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['respondida_em' => 'datetime', 'regiao_atualizada_em' => 'datetime'];
    }

    public function agencia()
    {
        return $this->belongsTo(AgenciaRede::class, 'agencia_rede_id');
    }

    public function industria()
    {
        return $this->belongsTo(Tenant::class, 'industria_tenant_id');
    }
}
