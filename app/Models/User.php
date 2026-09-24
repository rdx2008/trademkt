<?php

namespace App\Models;

use App\Enums\Perfil;
use App\Enums\TipoVinculo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Usuário de uma instalação (banco do cliente).
 *
 * @property Perfil $perfil
 * @property ?TipoVinculo $tipo_vinculo
 */
class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'nome',
        'email',
        'telefone',
        'password',
        'perfil',
        'industria_id',
        'agencia_id',
        'tipo_vinculo',
        'ativo',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'perfil' => Perfil::class,
            'tipo_vinculo' => TipoVinculo::class,
            'ativo' => 'boolean',
            'ultimo_acesso_em' => 'datetime',
        ];
    }

    public function industria(): BelongsTo
    {
        return $this->belongsTo(Industria::class);
    }

    public function agencia(): BelongsTo
    {
        return $this->belongsTo(Agencia::class);
    }

    public function temPerfil(Perfil ...$perfis): bool
    {
        return in_array($this->perfil, $perfis, true);
    }

    public function podeGerenciar(User $outro): bool
    {
        if ($this->is($outro)) {
            return false;
        }

        if (! in_array($outro->perfil, $this->perfil->podeGerenciar(), true)) {
            return false;
        }

        return match ($this->perfil) {
            Perfil::AdminInstalacao => true,
            Perfil::AdminAgencia => $outro->agencia_id === $this->agencia_id,
            Perfil::GerenteTrade => $outro->industria_id === $this->industria_id,
            default => false,
        };
    }

    /**
     * Restringe uma listagem de usuários ao que este usuário pode ver.
     * Indústria vê a sua indústria; agência vê a sua agência.
     */
    public function scopeVisiveisPara(Builder $query, User $usuario): Builder
    {
        return match ($usuario->perfil) {
            Perfil::AdminInstalacao => $query,
            Perfil::AdminAgencia, Perfil::Supervisor => $query->where('agencia_id', $usuario->agencia_id),
            Perfil::GerenteTrade => $query->where(function (Builder $q) use ($usuario) {
                $q->where('industria_id', $usuario->industria_id)
                    ->orWhereIn('perfil', [Perfil::Supervisor->value, Perfil::Promotor->value]);
            }),
            default => $query->whereKey($usuario->getKey()),
        };
    }
}
