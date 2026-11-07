<?php

namespace App\Models;

use App\Enums\Perfil;
use App\Enums\TipoVinculo;
use App\Support\Contexto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    /** O usuário é de uma agência vinculada e está operando dentro da instalação de uma indústria. */
    public function agenciaVinculada(): bool
    {
        return Contexto::viaAgencia();
    }

    /** Lojas do gerente do supermercado. */
    public function pdvsGerenciados(): BelongsToMany
    {
        return $this->belongsToMany(Pdv::class, 'pdv_usuarios')->withTimestamps();
    }

    /** Carteira de PDVs do representante comercial. */
    public function carteiraPdvs(): BelongsToMany
    {
        return $this->belongsToMany(Pdv::class, 'representante_pdv')->withTimestamps();
    }

    public function temPerfil(Perfil ...$perfis): bool
    {
        return in_array($this->perfil, $perfis, true);
    }

    /** BI (análise de resultados): gestão da instalação, agência e marca; promotor, representante e supermercado não. */
    public function podeVerBi(): bool
    {
        return $this->temPerfil(Perfil::AdminInstalacao, Perfil::AdminAgencia, Perfil::Supervisor, Perfil::GerenteTrade);
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
     * Indústria vê a sua indústria e a equipe das prestadoras; agência vê a sua agência.
     */
    public function scopeVisiveisPara(Builder $query, User $usuario): Builder
    {
        return match ($usuario->perfil) {
            Perfil::AdminInstalacao => $query,
            Perfil::AdminAgencia, Perfil::Supervisor => $query->where('agencia_id', $usuario->agencia_id),
            Perfil::GerenteTrade => $usuario->industria_id === null
                ? $query->whereKey($usuario->getKey())
                : $query->where(function (Builder $q) use ($usuario) {
                    $q->where('industria_id', $usuario->industria_id)
                        ->orWhere(fn (Builder $equipe) => $equipe
                            ->whereIn('perfil', [Perfil::AdminAgencia->value, Perfil::Supervisor->value, Perfil::Promotor->value])
                            ->whereIn('agencia_id', Agencia::visiveisPara($usuario)->select('agencias.id')));
                }),
            default => $query->whereKey($usuario->getKey()),
        };
    }
}
