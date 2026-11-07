<?php

namespace App\Support;

use App\Enums\TipoInstalacao;
use App\Models\Agencia;
use App\Models\Tenant;

/**
 * Em que instalação o usuário entrou e em qual operação ele está trabalhando.
 *
 * Normalmente são a mesma: a instalação identificada pelo domínio. Para a agência, porém, a
 * operação (PDVs, contratos, roteiros, visitas) mora no banco da indústria vinculada: o usuário
 * entra na instalação da agência e o sistema abre a operação da indústria escolhida.
 */
class Contexto
{
    private static ?Tenant $instalacao = null;

    private static ?Tenant $operacao = null;

    private static ?Agencia $agenciaLocal = null;

    /** Instalação em que o usuário entrou (pelo domínio). */
    public static function instalacao(): ?Tenant
    {
        return self::$instalacao ?? (function_exists('tenancy') && tenancy()->initialized ? tenant() : null);
    }

    /** Indústria cuja operação está aberta, quando a agência está operando nela. */
    public static function operacao(): ?Tenant
    {
        return self::$operacao;
    }

    /** Linha da agência no banco da indústria aberta. */
    public static function agenciaLocal(): ?Agencia
    {
        return self::$agenciaLocal;
    }

    /** O usuário é de uma agência vinculada e está dentro da operação de uma indústria. */
    public static function viaAgencia(): bool
    {
        return self::$operacao !== null;
    }

    /** A instalação de entrada é uma agência (com ou sem operação aberta). */
    public static function instalacaoEhAgencia(): bool
    {
        return self::instalacao()?->tipo === TipoInstalacao::Agencia;
    }

    public static function abrirOperacao(Tenant $instalacao, Tenant $industria, Agencia $agenciaLocal): void
    {
        self::$instalacao = $instalacao;
        self::$operacao = $industria;
        self::$agenciaLocal = $agenciaLocal;
    }

    public static function limpar(): void
    {
        self::$instalacao = null;
        self::$operacao = null;
        self::$agenciaLocal = null;
    }
}
