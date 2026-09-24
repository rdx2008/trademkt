<?php

namespace App\Enums;

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
     * Perfis que este perfil pode cadastrar/editar.
     *
     * @return list<self>
     */
    public function podeGerenciar(): array
    {
        return match ($this) {
            self::AdminInstalacao => self::cases(),
            self::AdminAgencia => [self::Supervisor, self::Promotor],
            self::GerenteTrade => [self::Representante],
            default => [],
        };
    }

    /** @return array<string, string> valor => rótulo */
    public static function opcoes(): array
    {
        $opcoes = [];
        foreach (self::cases() as $perfil) {
            $opcoes[$perfil->value] = $perfil->label();
        }

        return $opcoes;
    }
}
