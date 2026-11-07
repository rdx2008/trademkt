<?php

namespace App\Models;

use App\Enums\Perfil;
use App\Services\Operacao\Equipe;
use Illuminate\Database\Eloquent\Model;

class Demanda extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['foto', 'payload_hash'];

    protected function casts(): array
    {
        return ['skus' => 'array', 'prazo_em' => 'datetime', 'atendida_em' => 'datetime', 'atraso_notificado_em' => 'datetime'];
    }

    public function visita()
    {
        return $this->belongsTo(Visita::class);
    }

    public function pdv()
    {
        return $this->belongsTo(Pdv::class);
    }

    public function industria()
    {
        return $this->belongsTo(Industria::class);
    }

    public function agencia()
    {
        return $this->belongsTo(Agencia::class);
    }

    /** Quem pediu: usuário desta instalação ou, com `solicitante_agencia_id`, pessoa da agência. */
    public function nomeSolicitante(): ?string
    {
        if ($this->solicitante_agencia_id) {
            return app(Equipe::class)->nome($this->solicitante_agencia_id, $this->solicitante_id);
        }

        return User::find($this->solicitante_id)?->nome;
    }

    public function atrasada(): bool
    {
        return $this->status !== 'recusada' && ($this->atendida_em ? $this->atendida_em->gt($this->prazo_em) : now()->gt($this->prazo_em));
    }

    public function scopeVisiveisPara($q, User $u)
    {
        return match ($u->perfil) {
            Perfil::AdminInstalacao => $q,
            Perfil::AdminAgencia, Perfil::Supervisor => $q->where('agencia_id', $u->agencia_id),
            Perfil::GerenteTrade => $q->where('industria_id', $u->industria_id),
            Perfil::Representante => $q->where('industria_id', $u->industria_id)->whereIn('pdv_id', $u->carteiraPdvs()->select('pdvs.id')),
            Perfil::GerentePdv => $q->whereIn('pdv_id', $u->pdvsGerenciados()->select('pdvs.id')),
            Perfil::Promotor => $q->where('agencia_id', $u->agencia_id)->whereHas('visita.roteiro', fn ($r) => $r->where('promotor_id', $u->id)),
        };
    }
}
