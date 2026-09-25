<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contrato de frequência de visitas de uma indústria num PDV, executado por uma agência.
 * O cadastro é o item 2.4 da fase 2.
 *
 * @property list<int> $dias 1 = segunda ... 7 = domingo
 */
class ContratoVisita extends Model
{
    protected $table = 'contratos_visita';

    protected $fillable = [
        'pdv_id', 'industria_id', 'agencia_id', 'frequencia_semana', 'dias', 'inicio', 'fim', 'ativo',
    ];

    protected function casts(): array
    {
        return [
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
}
