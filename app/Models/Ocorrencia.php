<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Fato registrado sobre o promotor pela gestão da agência. */
class Ocorrencia extends Model
{
    public const TIPOS = [
        'elogio' => 'Elogio',
        'atraso' => 'Atraso',
        'falta' => 'Falta',
        'advertencia' => 'Advertência',
        'orientacao' => 'Orientação',
        'outro' => 'Outro',
    ];

    protected $table = 'ocorrencias';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'date'];
    }

    public function rotulo(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }
}
