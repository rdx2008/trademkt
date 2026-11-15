<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Resposta de um modelo de coleta numa visita (uma por modelo, ou uma por SKU nos modelos por SKU). */
class ColetaResposta extends Model
{
    protected $table = 'coleta_respostas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['respostas' => 'array', 'respondida_em' => 'datetime', 'recebida_em' => 'datetime', 'versao' => 'integer'];
    }

    public function modelo(): BelongsTo
    {
        return $this->belongsTo(ModeloColeta::class, 'modelo_id');
    }

    public function visita(): BelongsTo
    {
        return $this->belongsTo(Visita::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
