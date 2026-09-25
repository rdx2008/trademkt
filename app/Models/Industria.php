<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Industria extends Model
{
    protected $table = 'industrias';

    protected $fillable = ['nome', 'cnpj', 'segmento', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function skus(): HasMany
    {
        return $this->hasMany(Sku::class);
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
