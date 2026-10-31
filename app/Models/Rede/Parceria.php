<?php

namespace App\Models\Rede;

use App\Models\Tenant;
use App\Services\Rede\RegioesParceria;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class Parceria extends Model
{
    use CentralConnection;

    protected $table = 'parcerias';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['respondida_em' => 'datetime', 'regiao_atualizada_em' => 'datetime', 'regioes_interesse' => 'array', 'regioes_anteriores' => 'array'];
    }

    public function regioesInteresse(): array
    {
        return $this->regioes_interesse ?: ($this->uf ? [['uf' => $this->uf, 'cidade' => $this->cidade]] : []);
    }

    public function regioesRotulo(): string
    {
        return $this->regioesInteresse() ? RegioesParceria::rotulo($this->regioesInteresse()) : $this->regiao;
    }

    public function regioesAnterioresRotulo(): ?string
    {
        return $this->regioes_anteriores ? RegioesParceria::rotulo($this->regioes_anteriores) : $this->regiao_anterior;
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
