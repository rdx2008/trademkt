<?php

namespace App\Enums;

/** Como a empresa existe na rede (fase 9). */
enum ModoEmpresa: string
{
    /** Independente: ela mesma é dona do cadastro. */
    case Propria = 'propria';

    /** Agência interna de uma indústria (instalação de agência de verdade, dona = a indústria). */
    case Interna = 'interna';

    /** Indústria gerida por uma agência até a indústria assumir (dona = a agência). */
    case Gerida = 'gerida';

    public function label(): string
    {
        return match ($this) {
            self::Propria => 'Própria',
            self::Interna => 'Agência interna',
            self::Gerida => 'Gerida pela agência',
        };
    }
}
