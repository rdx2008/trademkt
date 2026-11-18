<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Fuso horário de cada empresa. O Brasil tem quatro fusos; "dia da visita", geração do roteiro,
 * encerramento do dia e horários das fotos dependem dele. Ao abrir uma instalação (ou a operação
 * de uma indústria) o fuso dela passa a valer; ao sair, volta o padrão (`APP_TIMEZONE`).
 *
 * Os horários são gravados no fuso da empresa (como antes, quando havia um fuso só). Trocar o fuso
 * de uma empresa não converte o que já foi gravado.
 */
final class Fuso
{
    public const OPCOES = [
        'America/Sao_Paulo' => 'Brasília (UTC−3): maior parte do país',
        'America/Manaus' => 'Amazonas, Mato Grosso, Mato Grosso do Sul, Rondônia e Roraima (UTC−4)',
        'America/Rio_Branco' => 'Acre e oeste do Amazonas (UTC−5)',
        'America/Noronha' => 'Fernando de Noronha (UTC−2)',
    ];

    public static function padrao(): string
    {
        return (string) config('trade.fuso_padrao', 'America/Sao_Paulo');
    }

    public static function de(?Tenant $empresa): string
    {
        $fuso = $empresa?->fuso;

        return is_string($fuso) && isset(self::OPCOES[$fuso]) ? $fuso : self::padrao();
    }

    public static function aplicar(string $fuso): void
    {
        config(['app.timezone' => $fuso]);
        date_default_timezone_set($fuso);
    }
}
