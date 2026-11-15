<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Formulário de coleta da indústria (preço, validade, estoque, share, checklist ou livre) que o
 * promotor responde dentro da visita. Cada campo: chave, rotulo, tipo, obrigatorio, por_sku, opcoes.
 *
 * @property list<array{chave: string, rotulo: string, tipo: string, obrigatorio: bool, por_sku: bool, opcoes?: list<string>}> $campos
 */
class ModeloColeta extends Model
{
    protected $table = 'modelos_coleta';

    protected $guarded = ['id'];

    public const TIPOS = [
        'preco' => 'Pesquisa de preço',
        'validade' => 'Validade (produtos a vencer)',
        'estoque' => 'Levantamento de estoque',
        'share' => 'Pesquisa de share',
        'checklist' => 'Checklist de execução',
        'livre' => 'Formulário livre',
    ];

    public const TIPOS_CAMPO = [
        'sim_nao' => 'Sim ou não',
        'numero' => 'Número',
        'quantidade' => 'Quantidade (inteiro)',
        'preco' => 'Preço (R$)',
        'texto' => 'Texto',
        'escolha' => 'Escolha de uma opção',
        'data' => 'Data',
    ];

    /** Papel do campo nos relatórios (o que a resposta significa), independente do nome da pergunta. */
    public const PAPEIS = [
        'preco_proprio' => 'Preço do nosso produto',
        'preco_promocional' => 'Preço promocional do nosso produto',
        'preco_concorrente' => 'Preço do concorrente',
        'validade' => 'Data de validade',
        'lote' => 'Lote',
        'quantidade_vencimento' => 'Quantidade com esta validade',
        'estoque_gondola' => 'Estoque na gôndola',
        'estoque_loja' => 'Estoque na loja',
        'estoque_deposito' => 'Estoque no depósito',
        'frentes_marca' => 'Frentes da nossa marca (share)',
        'frentes_concorrentes' => 'Frentes de concorrentes (share)',
    ];

    protected function casts(): array
    {
        return ['campos' => 'array', 'redes' => 'array', 'ativo' => 'boolean', 'versao' => 'integer'];
    }

    public function industria(): BelongsTo
    {
        return $this->belongsTo(Industria::class);
    }

    public function versoes(): HasMany
    {
        return $this->hasMany(ModeloColetaVersao::class, 'modelo_id');
    }

    /** Campos da versão pedida (a atual quando não há registro dela). */
    public function camposDaVersao(int $versao): array
    {
        if ($versao === $this->versao) {
            return $this->campos;
        }

        return $this->versoes()->where('versao', $versao)->first()?->campos ?? $this->campos;
    }

    /** Guarda a definição desta versão (chamado ao criar e a cada mudança de campos). */
    public function registrarVersao(): void
    {
        ModeloColetaVersao::updateOrCreate(['modelo_id' => $this->id, 'versao' => $this->versao], ['campos' => $this->campos, 'created_at' => now()]);
    }

    public function respostas(): HasMany
    {
        return $this->hasMany(ColetaResposta::class, 'modelo_id');
    }

    /** Chave do campo que tem o papel, na versão dada (a atual por padrão). */
    public function chaveDoPapel(string $papel, ?int $versao = null): ?string
    {
        $campos = $versao === null ? $this->campos : $this->camposDaVersao($versao);

        return collect($campos)->firstWhere('papel', $papel)['chave'] ?? null;
    }

    /** O modelo é "por SKU" quando algum campo é respondido para cada produto. */
    public function porSku(): bool
    {
        return collect($this->campos)->contains(fn ($c) => ! empty($c['por_sku']));
    }

    /** Modelos ativos que valem para a loja (rede) da visita. */
    public function scopeParaPdv(Builder $query, ?int $redeId): Builder
    {
        return $query->where('ativo', true)->where(function (Builder $q) use ($redeId) {
            $q->whereNull('redes')->orWhere('redes', '[]');
            if ($redeId) {
                $q->orWhereJsonContains('redes', $redeId);
            }
        });
    }

    public function rotuloTipo(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }
}
