<?php

namespace App\Models;

use App\Enums\TipoEmbalagem;
use App\Rules\Gtin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Embalagem de um SKU (unidade, reembalagem, caixa master), cada uma com o seu GTIN.
 *
 * @property TipoEmbalagem $tipo
 * @property ?bool $gtin_valido null = sem GTIN; false = dígito verificador errado
 */
class SkuEmbalagem extends Model
{
    protected $table = 'sku_embalagens';

    protected $fillable = ['sku_id', 'tipo', 'quantidade', 'gtin', 'codigo'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoEmbalagem::class,
            'quantidade' => 'integer',
            'gtin_valido' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // GTIN guardado só com dígitos; o dígito verificador errado fica marcado.
        static::saving(function (SkuEmbalagem $embalagem) {
            $gtin = Gtin::normalizar($embalagem->gtin);
            $embalagem->gtin = $gtin === '' ? null : $gtin;
            $embalagem->gtin_valido = $embalagem->gtin === null ? null : Gtin::digitoCorreto($embalagem->gtin);
        });
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    public function gtinComAlerta(): bool
    {
        return $this->gtin !== null && $this->gtin_valido === false;
    }
}
