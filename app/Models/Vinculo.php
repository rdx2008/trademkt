<?php

namespace App\Models;

use App\Services\Rede\Regioes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Vínculo entre uma indústria e uma agência, criado pelo super admin (banco central).
 * A agência vinculada opera dentro da instalação da indústria com a própria equipe.
 *
 * @property string $industria_tenant_id
 * @property string $agencia_tenant_id
 * @property ?list<array{uf: string, cidade: ?string}> $regioes
 * @property bool $ativo
 */
class Vinculo extends Model
{
    use CentralConnection;

    protected $table = 'vinculos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['regioes' => 'array', 'ativo' => 'boolean', 'permissoes' => 'array', 'decidido_em' => 'datetime'];
    }

    public const PENDENTE = 'pendente';

    public const ATIVO = 'ativo';

    public const RECUSADO = 'recusado';

    public const ENCERRADO = 'encerrado';

    public const RELACOES = ['prospect' => 'Prospect', 'cliente' => 'Cliente', 'encerrado' => 'Encerrado'];

    /** Permissões da agência dentro da indústria, com o padrão de cada uma (fase 9). */
    public const PERMISSOES = ['agencia_mantem_catalogo' => true];

    public function permite(string $permissao): bool
    {
        return (bool) (($this->permissoes ?? [])[$permissao] ?? self::PERMISSOES[$permissao] ?? false);
    }

    public function agenciaMantemCatalogo(): bool
    {
        return $this->permite('agencia_mantem_catalogo');
    }

    public function industria(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'industria_tenant_id');
    }

    public function agencia(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'agencia_tenant_id');
    }

    public function scopeAtivos(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }

    /** Vínculos ativos de uma instalação (indústria ou agência), com as duas pontas ativas. */
    public function scopeDe(Builder $query, Tenant $instalacao): Builder
    {
        return $query->ativos()
            ->where(fn (Builder $q) => $q->where('industria_tenant_id', $instalacao->id)->orWhere('agencia_tenant_id', $instalacao->id))
            ->whereHas('industria', fn (Builder $t) => $t->where('ativo', true))
            ->whereHas('agencia', fn (Builder $t) => $t->where('ativo', true));
    }

    public function regioesRotulo(): string
    {
        return $this->regioes ? Regioes::rotulo($this->regioes) : 'Todas as regiões';
    }
}
