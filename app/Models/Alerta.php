<?php

namespace App\Models;

use App\Services\Painel\ResumoOperacional;
use App\Support\Contexto;
use Illuminate\Database\Eloquent\Model;

/**
 * Aviso individual. `usuario_id` é o id do destinatário; `agencia_id` diz em que banco ele
 * mora (nulo = usuário desta instalação; senão, a agência vinculada dona desse id).
 */
class Alerta extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lida_em' => 'datetime'];
    }

    public function scopeDoUsuario($q, User $u)
    {
        return $q->where('usuario_id', $u->id)->where('agencia_id', Contexto::viaAgencia() ? Contexto::agenciaLocal()->id : null);
    }

    public function scopeVisiveisPara($q, User $u)
    {
        return $q->doUsuario($u)->where(fn ($r) => $r
            ->whereIn('visita_id', app(ResumoOperacional::class)->visitas($u)->select('id'))
            ->orWhereIn('demanda_id', Demanda::visiveisPara($u)->select('id')));
    }
}
