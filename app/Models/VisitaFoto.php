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

    public function visita()
    {
        return $this->belongsTo(Visita::class);
    }
}
