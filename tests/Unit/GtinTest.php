<?php

namespace Tests\Unit;

use App\Rules\Gtin;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Validator;
use PHPUnit\Framework\TestCase;

class GtinTest extends TestCase
{
    public function test_digito_verificador_nos_quatro_tamanhos(): void
    {
        foreach (['96385074', '036000291452', '7891000315507', '17891000315504'] as $gtin) {
            $this->assertTrue(Gtin::valido($gtin), $gtin);
        }

        foreach (['96385075', '036000291453', '7891000315508', '17891000315505'] as $gtin) {
            $this->assertTrue(Gtin::formatoValido($gtin), $gtin);
            $this->assertFalse(Gtin::digitoCorreto($gtin), $gtin);
        }
    }

    public function test_formato(): void
    {
        $this->assertTrue(Gtin::formatoValido(' 789 1000-315507 '));
        $this->assertSame('7891000315507', Gtin::normalizar(' 789 1000-315507 '));
        $this->assertFalse(Gtin::formatoValido('1234567'));       // 7 dígitos
        $this->assertFalse(Gtin::formatoValido('12345678901'));   // 11 dígitos
        $this->assertFalse(Gtin::formatoValido('789100031550A'));
        $this->assertFalse(Gtin::formatoValido(''));
    }

    public function test_regra_recusa_formato_e_so_exige_digito_quando_pedido(): void
    {
        $validar = fn ($valor, Gtin $regra) => (new Validator(
            new Translator(new ArrayLoader, 'pt_BR'), ['gtin' => $valor], ['gtin' => [$regra]]
        ))->passes();

        $this->assertTrue($validar('7891000315507', new Gtin));
        $this->assertTrue($validar('7891000315508', new Gtin), 'Dígito errado é aceito por padrão.');
        $this->assertFalse($validar('7891000315508', new Gtin(exigirDigito: true)));
        $this->assertFalse($validar('12345', new Gtin));
    }
}
