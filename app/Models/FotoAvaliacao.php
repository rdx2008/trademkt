<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Avaliação de uma foto da visita: nota de 1 a 5 e tags, por avaliador. */
class FotoAvaliacao extends Model
{
    protected $table = 'foto_avaliacoes';

    protected $guarded = ['id'];

    /** Tags prontas para classificar a foto. */
    public const TAGS = [
        'gondola_ok' => 'Gôndola ok',
        'refazer' => 'Refazer a execução',
        'foto_ruim' => 'Foto ruim',
        'ruptura' => 'Ruptura visível',
        'material_pdv' => 'Material de PDV',
        'concorrente' => 'Concorrente em destaque',
    ];

    protected function casts(): array
    {
        return ['tags' => 'array', 'nota' => 'integer'];
    }

    public function foto(): BelongsTo
    {
        return $this->belongsTo(VisitaFoto::class, 'foto_id');
    }
}
