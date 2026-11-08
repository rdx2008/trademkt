<?php

namespace App\Models;

use App\Enums\Perfil;
use App\Enums\TipoVinculo;
use App\Rules\Cpf;
use App\Support\Contexto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
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
        'cpf',
        'cpf_hash',
        'foto',
        'cidade_base',
        'uf_base',
        'observacoes',
        'aceite_privacidade_em',
    ];

    protected $hidden = ['password', 'remember_token', 'cpf', 'cpf_hash', 'cracha_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'perfil' => Perfil::class,
            'tipo_vinculo' => TipoVinculo::class,
            'ativo' => 'boolean',
            'habilitacao_restrita' => 'boolean',
            'cpf' => 'encrypted',
            'ultimo_acesso_em' => 'datetime',
            'aceite_privacidade_em' => 'datetime',
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
    /** Promotores e supervisores consultam a equipe de campo; só o admin cadastra e edita. */
    public function podeVerPromotores(): bool
    {
        return $this->temPerfil(Perfil::AdminInstalacao, Perfil::AdminAgencia, Perfil::Supervisor);
    }

    public function podeGerirPromotores(): bool
    {
        return $this->temPerfil(Perfil::AdminInstalacao, Perfil::AdminAgencia);
    }

    /** CPF mascarado para listas (o número inteiro só aparece para quem edita o cadastro). */
    public function cpfMascarado(): string
    {
        return Cpf::mascarar($this->cpf);
    }

    public function convites(): HasMany
    {
        return $this->hasMany(Convite::class, 'usuario_id');
    }

    /**
     * Ids das instalações de indústria em que o promotor está habilitado. `null` = sem restrição
     * (todas as atendidas); lista vazia = nenhuma (promotor novo, até o admin habilitar).
     *
     * @return list<string>|null
     */
    public function industriasHabilitadas(): ?array
    {
        if (! $this->habilitacao_restrita) {
            return null;
        }

        return DB::table('promotor_industrias')->where('usuario_id', $this->id)->pluck('industria_id')->all();
    }

    public function afastamentos(): HasMany
    {
        return $this->hasMany(Afastamento::class, 'usuario_id')->orderByDesc('inicio');
    }

    public function ocorrencias(): HasMany
    {
        return $this->hasMany(Ocorrencia::class, 'usuario_id')->orderByDesc('data')->orderByDesc('id');
    }

    /** Afastamento que cobre a data (padrão: hoje), se houver. */
    public function afastamentoEm(?string $data = null): ?Afastamento
    {
        return $this->afastamentos()->em($data ?? now()->toDateString())->first();
    }

    public function auditoria(): HasMany
    {
        return $this->hasMany(AuditoriaUsuario::class, 'usuario_id')->latest('id');
    }

    /** Promotor que ainda não abriu o convite: não tem senha própria. */
    public function conviteAceito(): bool
    {
        return $this->aceite_privacidade_em !== null;
    }

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
