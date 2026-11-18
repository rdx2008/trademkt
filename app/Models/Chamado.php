<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** Chamado da central de atendimento: a empresa pergunta, o fornecedor responde (banco central). */
class Chamado extends Model
{
    use CentralConnection;

    protected $table = 'chamados';

    protected $guarded = ['id'];

    public const CATEGORIAS = ['duvida' => 'Dúvida', 'problema' => 'Problema', 'sugestao' => 'Sugestão', 'cobranca' => 'Cobrança'];

    public const SITUACOES = ['aberto' => 'Aguardando o fornecedor', 'respondido' => 'Respondido', 'fechado' => 'Fechado'];

    protected function casts(): array
    {
        return ['ultima_mensagem_em' => 'datetime'];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(ChamadoMensagem::class)->orderBy('id');
    }
}
