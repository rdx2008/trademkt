<?php

namespace Tests\Unit;

use App\Enums\CanalPdv;
use App\Rules\Cnpj;
use PHPUnit\Framework\TestCase;

class CnpjTest extends TestCase
{
    public function test_valida_digitos_verificadores(): void
    {
        $this->assertTrue(Cnpj::valido('11.222.333/0001-81'));
        $this->assertTrue(Cnpj::valido('11222333000181'));
        $this->assertFalse(Cnpj::valido('11.222.333/0001-82'));
        $this->assertFalse(Cnpj::valido('11111111111111'));
        $this->assertFalse(Cnpj::valido('1122233300018'));
        $this->assertFalse(Cnpj::valido(''));
    }

    public function test_limpa_e_formata(): void
    {
        $this->assertSame('11222333000181', Cnpj::limpar('11.222.333/0001-81'));
        $this->assertSame('11.222.333/0001-81', Cnpj::formatar('11222333000181'));
    }

    public function test_canal_aceita_rotulo_da_planilha(): void
    {
        $this->assertSame(CanalPdv::Farmacia, CanalPdv::deTexto('Farmácia'));
        $this->assertSame(CanalPdv::Supermercado, CanalPdv::deTexto(' SUPERMERCADO '));
        $this->assertNull(CanalPdv::deTexto('padaria'));
        $this->assertNull(CanalPdv::deTexto(''));
    }
}
