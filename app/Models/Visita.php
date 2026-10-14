<?php

namespace App\Models;

use App\Enums\OrigemVisita;
use App\Enums\Perfil;
use App\Enums\StatusVisita;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Visita a um PDV para uma indústria. Sem roteiro = ainda não atribuída a um promotor.
 * "data" é o dia em que será feita; "data_prevista" é o dia que o contrato previu (só origem contrato).
 *
 * @property OrigemVisita $origem
 * @property StatusVisita $status
 */
class Visita extends Model
{
    protected $table = 'visitas';

    protected $fillable = [
        'roteiro_id', 'data', 'data_prevista', 'agencia_id', 'pdv_id', 'industria_id', 'contrato_id', 'origem', 'status', 'ordem',
    ];

    protected $attributes = [
        'status' => 'previsto',
        'ordem' => 0,
    ];

    protected function casts(): array
    {
        return [
            'data' => 'date',
            'data_prevista' => 'date',
            'origem' => OrigemVisita::class,
            'status' => StatusVisita::class,
            'ordem' => 'integer',
        ];
    }

    public function roteiro(): BelongsTo
    {
        return $this->belongsTo(Roteiro::class);
    }

    public function agencia(): BelongsTo
    {
        return $this->belongsTo(Agencia::class);
    }

    public function pdv(): BelongsTo
    {
        return $this->belongsTo(Pdv::class);
    }

    public function industria(): BelongsTo
    {
        return $this->belongsTo(Industria::class);
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(ContratoVisita::class, 'contrato_id');
    }

    /** Só visita prevista, de hoje em diante, pode mudar de promotor ou de dia. */
    public function podeSerMovida(): bool
    {
        return $this->status === StatusVisita::Previsto && ! $this->data->isBefore(today());
    }

    /**
     * Visitas que o usuário pode ver. A agência vê as que executa; a indústria, as da sua marca.
     */
    public function scopeVisiveisPara(Builder $query, User $usuario): Builder
    {
        return match ($usuario->perfil) {
            Perfil::AdminInstalacao => $query,
            Perfil::AdminAgencia, Perfil::Supervisor => $query->where('agencia_id', $usuario->agencia_id),
            Perfil::GerenteTrade, Perfil::Representante => $query->where('industria_id', $usuario->industria_id),
            Perfil::Promotor => $query->whereHas('roteiro', fn (Builder $r) => $r->where('promotor_id', $usuario->id)),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Regra trabalhista: só a agência (supervisor ou admin da agência) atribui visitas a promotores.
     * A indústria e o admin da instalação acompanham, mas não editam.
     */
    public static function podeAtribuir(User $usuario): bool
    {
        return $usuario->temPerfil(Perfil::Supervisor, Perfil::AdminAgencia) && $usuario->agencia_id !== null;
    }
}
