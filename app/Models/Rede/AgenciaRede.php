<?php

namespace App\Models\Rede;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class AgenciaRede extends Model
{
    use CentralConnection;

    protected $table = 'agencias_rede';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['aprovada' => 'boolean', 'disponivel' => 'boolean'];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function regioes()
    {
        return $this->hasMany(AgenciaRegiao::class);
    }
}
