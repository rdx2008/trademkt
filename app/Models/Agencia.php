<?php

namespace App\Models;

use App\Enums\Perfil;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agencia extends Model
{
    protected $table = 'agencias';

    protected $fillable = ['nome', 'cnpj', 'ativo', 'agencia_tenant_id'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function contratos(): HasMany
    {
        return $this->hasMany(ContratoVisita::class);
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Prestadoras da indústria, incluindo contratos encerrados para consulta do histórico. */
    public function scopeVisiveisPara(Builder $query, User $usuario): Builder
    {
        return match ($usuario->perfil) {
            Perfil::AdminInstalacao => $query,
            Perfil::AdminAgencia, Perfil::Supervisor => $usuario->agencia_id === null
                ? $query->whereRaw('1 = 0')
                : $query->whereKey($usuario->agencia_id),
            Perfil::GerenteTrade, Perfil::Representante => $usuario->industria_id === null
                ? $query->whereRaw('1 = 0')
                : $query->where(fn (Builder $q) => $q->whereHas('contratos', fn (Builder $c) => $c->where('industria_id', $usuario->industria_id))
                    ->orWhere(fn (Builder $v) => $v->whereNotNull('agencia_tenant_id')->where('ativo', true))),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
