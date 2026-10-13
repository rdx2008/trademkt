<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dia de trabalho de um promotor: as visitas que ele faz numa data, em ordem.
 */
class Roteiro extends Model
{
    protected $table = 'roteiros';

    protected $fillable = ['promotor_id', 'data', 'supervisor_id'];

    protected function casts(): array
    {
        return ['data' => 'date'];
    }

    public function promotor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'promotor_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function visitas(): HasMany
    {
        return $this->hasMany(Visita::class)->orderBy('ordem')->orderBy('id');
    }
}
