<?php

namespace App\Models;

use App\Enums\Perfil;
use App\Enums\TipoEmbalagem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Produto do catálogo de uma indústria. O código é único dentro da indústria.
 */
class Sku extends Model
{
    protected $table = 'skus';

    protected $fillable = ['industria_id', 'codigo', 'descricao', 'categoria', 'foto', 'ativo'];

    protected $attributes = ['ativo' => true];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function industria(): BelongsTo
    {
        return $this->belongsTo(Industria::class);
    }

    public function embalagens(): HasMany
    {
        return $this->hasMany(SkuEmbalagem::class);
    }

    /**
     * Embalagens na ordem de exibição: unidade, reembalagem, caixa master; depois por quantidade.
     *
     * @return Collection<int, SkuEmbalagem>
     */
    public function embalagensOrdenadas(): Collection
    {
        $ordem = array_flip(array_map(fn (TipoEmbalagem $t) => $t->value, TipoEmbalagem::cases()));

        return $this->embalagens
            ->sortBy([fn ($a, $b) => $ordem[$a->tipo->value] <=> $ordem[$b->tipo->value], ['quantidade', 'asc'], ['id', 'asc']])
            ->values();
    }

    /** SKUs com algum GTIN de dígito verificador errado. */
    public function scopeComAlertaDeGtin(Builder $query): Builder
    {
        return $query->whereHas('embalagens', fn (Builder $e) => $e->where('gtin_valido', false));
    }

    /**
     * SKUs que o usuário pode ver. A indústria só enxerga o catálogo da sua marca.
     */
    public function scopeVisiveisPara(Builder $query, User $usuario): Builder
    {
        return match ($usuario->perfil) {
            Perfil::AdminInstalacao, Perfil::AdminAgencia, Perfil::Supervisor => $query,
            Perfil::GerenteTrade, Perfil::Representante => $query->where('industria_id', $usuario->industria_id),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** Quem cadastra e edita o catálogo: o admin da instalação e o gerente de trade da marca. */
    public function podeSerEditadoPor(User $usuario): bool
    {
        return match ($usuario->perfil) {
            Perfil::AdminInstalacao => true,
            Perfil::GerenteTrade => $this->industria_id === $usuario->industria_id,
            default => false,
        };
    }
}
