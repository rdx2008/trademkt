<?php

namespace App\Models;

use App\Services\Painel\ResumoOperacional;
use Illuminate\Database\Eloquent\Model;

class Alerta extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lida_em' => 'datetime'];
    }

    public function scopeVisiveisPara($q, User $u)
    {
        return $q->where('usuario_id', $u->id)->where(fn ($r) => $r
            ->whereIn('visita_id', app(ResumoOperacional::class)->visitas($u)->select('id'))
            ->orWhereIn('demanda_id', Demanda::visiveisPara($u)->select('id')));
    }
}
