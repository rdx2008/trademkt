<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rede extends Model
{
    protected $table = 'redes';

    protected $fillable = ['nome', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function pdvs(): HasMany
    {
        return $this->hasMany(Pdv::class);
    }
}
