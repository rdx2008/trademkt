<?php

namespace App\Enums;

use App\Support\Contexto;

/**
 * Perfis de usuário dentro de uma instalação (cliente).
 * O super admin do fornecedor fica no banco central (App\Models\Admin).
 */
enum Perfil: string
{
    case AdminInstalacao = 'admin_instalacao';
    case GerenteTrade = 'gerente_trade';
    case Representante = 'representante';
    case AdminAgencia = 'admin_agencia';
    case Supervisor = 'supervisor';
    case Promotor = 'promotor';
    case GerentePdv = 'gerente_pdv';

    public function label(): string
    {
        return match ($this) {
            self::AdminInstalacao => 'Admin da instalação',
            self::GerenteTrade => 'Gerente de trade',
            self::Representante => 'Representante comercial',
            self::AdminAgencia => 'Admin da agência',
            self::Supervisor => 'Supervisor',
            self::Promotor => 'Promotor',
            self::GerentePdv => 'Gerente do supermercado',
        };
    }

    /** Grupo do perfil: instalacao | industria | agencia | pdv */
    public function grupo(): string
    {
        return match ($this) {
            self::AdminInstalacao => 'instalacao',
            self::GerenteTrade, self::Representante => 'industria',
            self::AdminAgencia, self::Supervisor, self::Promotor => 'agencia',
            self::GerentePdv => 'pdv',
        };
    }

    public function exigeIndustria(): bool
    {
        return $this->grupo() === 'industria';
    }

    public function exigeAgencia(): bool
    {
        return $this->grupo() === 'agencia';
    }

    /** Perfis que entram pelo app (os demais usam o painel web). */
    public function usaApp(): bool
    {
        return $this === self::Promotor;
    }

    /**
     * Perfis que existem numa instalação: a indústria tem a sua gestão, o comercial e as lojas;
     * a agência tem a sua gestão e os promotores. Promotor nunca pertence à indústria.
     *
     * @return list<self>
     */
    public static function daInstalacao(?TipoInstalacao $tipo): array
    {
        return match ($tipo) {
            TipoInstalacao::Industria => [self::AdminInstalacao, self::GerenteTrade, self::Representante, self::GerentePdv],
            TipoInstalacao::Agencia => [self::AdminInstalacao, self::AdminAgencia, self::Supervisor, self::Promotor],
            default => self::cases(),
        };
    }

    /**
     * Perfis que este perfil pode cadastrar/editar na instalação atual.
     *
     * @return list<self>
     */
    public function podeGerenciar(?TipoInstalacao $tipo = null): array
    {
        $tipo ??= Contexto::instalacao()?->tipo;

        // Promotor tem tela própria (Promotores), com CPF, foto e convite: não entra por Usuários.
        return match ($this) {
            self::AdminInstalacao => array_values(array_filter(self::daInstalacao($tipo), fn (self $p) => $p !== self::Promotor)),
            self::AdminAgencia => [self::Supervisor],
            self::GerenteTrade => [self::Representante],
            default => [],
        };
    }

    /** @return array<string, string> valor => rótulo, só dos perfis da instalação */
    public static function opcoes(?TipoInstalacao $tipo = null): array
    {
        $opcoes = [];
        foreach (self::daInstalacao($tipo ?? Contexto::instalacao()?->tipo) as $perfil) {
            $opcoes[$perfil->value] = $perfil->label();
        }

        return $opcoes;
    }
}
