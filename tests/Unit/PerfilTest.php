<?php

namespace Tests\Unit;

use App\Enums\Perfil;
use PHPUnit\Framework\TestCase;

class PerfilTest extends TestCase
{
    public function test_grupos_e_vinculos(): void
    {
        $this->assertTrue(Perfil::GerenteTrade->exigeIndustria());
        $this->assertTrue(Perfil::Representante->exigeIndustria());
        $this->assertTrue(Perfil::Promotor->exigeAgencia());
        $this->assertTrue(Perfil::Supervisor->exigeAgencia());
        $this->assertFalse(Perfil::GerentePdv->exigeAgencia());
        $this->assertTrue(Perfil::Promotor->usaApp());
        $this->assertFalse(Perfil::Supervisor->usaApp());
    }

    public function test_quem_gerencia_quem(): void
    {
        $this->assertCount(7, Perfil::AdminInstalacao->podeGerenciar());
        $this->assertSame([Perfil::Supervisor, Perfil::Promotor], Perfil::AdminAgencia->podeGerenciar());
        $this->assertSame([Perfil::Representante], Perfil::GerenteTrade->podeGerenciar());
        $this->assertSame([], Perfil::Promotor->podeGerenciar());
    }
}
