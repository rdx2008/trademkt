<?php

namespace App\Models;

use App\Enums\Perfil;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contrato de frequência de visitas de uma indústria num PDV, executado por uma agência.
 *
 * @property list<int> $dias 1 = segunda ... 7 = domingo (ISO-8601)
 * @property ?CarbonInterface $fim null = sem data de término
 */
class ContratoVisita extends Model
{
    public const FREQUENCIAS = [1, 2, 3];

    public const DIAS = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];

    protected $table = 'contratos_visita';

    protected $fillable = [
        'pdv_id', 'industria_id', 'agencia_id', 'frequencia_semana', 'dias', 'inicio', 'fim', 'ativo',
    ];

    protected $attributes = ['ativo' => true];

    protected function casts(): array
    {
        return [
            'frequencia_semana' => 'integer',
            'dias' => 'array',
            'inicio' => 'date',
            'fim' => 'date',
            'ativo' => 'boolean',
        ];
    }

    public function pdv(): BelongsTo
    {
        return $this->belongsTo(Pdv::class);
    }

    public function industria(): BelongsTo
    {
        return $this->belongsTo(Industria::class);
    }

    public function agencia(): BelongsTo
    {
        return $this->belongsTo(Agencia::class);
    }

    public function scopeAtivos(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }

    /** Contratos ativos cuja vigência cobre a data (hoje, se omitida). */
    public function scopeVigentesEm(Builder $query, ?CarbonInterface $data = null): Builder
    {
        $dia = ($data ?? now())->toDateString();

        return $query->ativos()
            ->whereDate('inicio', '<=', $dia)
            ->where(fn (Builder $q) => $q->whereNull('fim')->orWhereDate('fim', '>=', $dia));
    }

    /**
     * Contratos ativos do mesmo PDV e indústria cuja vigência cruza o período informado.
     */
    public function scopeSobrepostos(Builder $query, int $pdvId, int $industriaId, string $inicio, ?string $fim): Builder
    {
        return $query->ativos()
            ->where('pdv_id', $pdvId)
            ->where('industria_id', $industriaId)
            ->where(fn (Builder $q) => $q->whereNull('fim')->orWhereDate('fim', '>=', $inicio))
            ->when($fim, fn (Builder $q) => $q->whereDate('inicio', '<=', $fim));
    }

    /**
     * Contratos que o usuário pode ver: a agência vê os seus; a indústria, os da sua marca.
     */
    public function scopeVisiveisPara(Builder $query, User $usuario): Builder
    {
        return match ($usuario->perfil) {
            Perfil::AdminInstalacao => $query,
            Perfil::AdminAgencia, Perfil::Supervisor => $query->where('agencia_id', $usuario->agencia_id),
            Perfil::GerenteTrade, Perfil::Representante => $query->where('industria_id', $usuario->industria_id),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** Quem cadastra e edita: admin da instalação e admin da agência (só os da sua agência). */
    public function podeSerEditadoPor(User $usuario): bool
    {
        return match ($usuario->perfil) {
            Perfil::AdminInstalacao => true,
            Perfil::AdminAgencia => $this->agencia_id === null || $this->agencia_id === $usuario->agencia_id,
            default => false,
        };
    }

    public function vigente(?CarbonInterface $data = null): bool
    {
        $dia = ($data ?? now())->copy()->startOfDay();

        return $this->ativo
            && $this->inicio->lte($dia)
            && ($this->fim === null || $this->fim->gte($dia));
    }

    /** Ex.: "Seg, Qui". */
    public function diasRotulo(): string
    {
        $dias = $this->dias ?? [];
        sort($dias);

        return implode(', ', array_map(fn (int $d) => self::DIAS[$d] ?? '?', $dias));
    }
}
