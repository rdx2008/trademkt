<?php

namespace App\Models;

use App\Enums\TipoInstalacao;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * Cliente (instalação). Fica no banco central.
 *
 * @property string $id Slug do cliente, também usado no nome do banco e da pasta
 * @property string $nome
 * @property TipoInstalacao $tipo
 * @property string $codigo Código que o promotor digita no app
 * @property bool $ativo
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    /**
     * O id é o slug informado no provisionamento (sem gerador de id). Sem isto o
     * pacote trata a chave como autoincremento e sobrescreve o id após o insert.
     */
    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }

    protected $casts = [
        'tipo' => TipoInstalacao::class,
        'ativo' => 'boolean',
    ];

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'nome',
            'tipo',
            'codigo',
            'ativo',
        ];
    }

    /** Domínio principal (o primeiro cadastrado). */
    public function dominioPrincipal(): ?string
    {
        return $this->domains()->orderBy('id')->value('domain');
    }

    public function url(): ?string
    {
        $dominio = $this->dominioPrincipal();

        return $dominio ? config('trade.esquema_url').'://'.$dominio : null;
    }
}
