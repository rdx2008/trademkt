<?php

namespace App\Models;

use App\Services\Operacao\Equipe;
use App\Support\Pessoa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dia de trabalho de um promotor: as visitas que ele faz numa data, em ordem.
 *
 * `promotor_id` e `supervisor_id` são ids de usuários da agência (`agencia_id`), que mora em
 * outro banco; por isso não há relação Eloquent com `users`. Nomes vêm de {@see Equipe}.
 */
class Roteiro extends Model
{
    protected $table = 'roteiros';

    protected $fillable = ['promotor_id', 'agencia_id', 'data', 'supervisor_id'];

    protected function casts(): array
    {
        return ['data' => 'date'];
    }

    public function agencia(): BelongsTo
    {
        return $this->belongsTo(Agencia::class);
    }

    public function visitas(): HasMany
    {
        return $this->hasMany(Visita::class)->orderBy('ordem')->orderBy('id');
    }

    /** Roteiro de um promotor (de uma agência) num dia. */
    public function scopeDoPromotor(Builder $query, ?int $agenciaId, int $promotorId, string $data): Builder
    {
        return $query->where('agencia_id', $agenciaId)->where('promotor_id', $promotorId)->whereDate('data', $data);
    }

    public function promotor(): ?Pessoa
    {
        return app(Equipe::class)->pessoa($this->agencia_id, $this->promotor_id);
    }

    public function supervisor(): ?Pessoa
    {
        return app(Equipe::class)->pessoa($this->agencia_id, $this->supervisor_id);
    }
}
