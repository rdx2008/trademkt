<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ausência planejada de um promotor, por período (inclusive nas duas pontas). */
class Afastamento extends Model
{
    public const TIPOS = [
        'ferias' => 'Férias',
        'atestado' => 'Atestado',
        'folga' => 'Folga',
        'outro' => 'Outro motivo',
    ];

    protected $table = 'afastamentos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['inicio' => 'date', 'fim' => 'date'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function rotulo(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    public function scopeEm(Builder $query, string $data): Builder
    {
        return $query->whereDate('inicio', '<=', $data)->whereDate('fim', '>=', $data);
    }
}
