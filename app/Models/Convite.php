<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Convite de primeiro acesso do promotor: link de uso único (guardado só como hash) em que ele
 * cria a própria senha e lê o aviso de privacidade.
 */
class Convite extends Model
{
    protected $table = 'convites';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expira_em' => 'datetime', 'usado_em' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function valido(): bool
    {
        return $this->usado_em === null && $this->expira_em->isFuture();
    }
}
