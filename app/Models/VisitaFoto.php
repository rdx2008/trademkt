<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitaFoto extends Model
{
    protected $table = 'visita_fotos';

    protected $guarded = ['id'];

    protected $hidden = ['caminho', 'hash_origem'];

    protected function casts(): array
    {
        return ['metadados' => 'array'];
    }

    public function avaliacoes()
    {
        return $this->hasMany(FotoAvaliacao::class, 'foto_id');
    }

    /** Média das notas dadas à foto (nulo sem notas). */
    public function notaMedia(): ?float
    {
        $notas = $this->avaliacoes->pluck('nota')->filter();

        return $notas->isEmpty() ? null : round($notas->avg(), 1);
    }

    public function visita()
    {
        return $this->belongsTo(Visita::class);
    }
}
