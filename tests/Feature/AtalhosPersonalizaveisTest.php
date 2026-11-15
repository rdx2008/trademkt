<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

/** Fase 10 (10.9d): atalhos do painel que cada pessoa reordena e oculta (no navegador). */
class AtalhosPersonalizaveisTest extends TestCase
{
    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->criarCliente('xyz');
        $this->admin = $this->tenant->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
    }

    public function test_painel_marca_os_atalhos_para_personalizar_e_traz_os_novos(): void
    {
        $this->actingAs($this->admin)->get('http://xyz.test/painel')->assertOk()
            ->assertSee('data-personalizavel', false)
            ->assertSee('data-chave="xyz:'.$this->admin->id.'"', false)
            ->assertSee('data-id="modelos-de-coleta"', false)
            ->assertSee('data-id="resultados-das-coletas"', false)
            ->assertSee('js/atalhos.js', false);
    }

    public function test_o_script_existe_e_guarda_so_no_navegador(): void
    {
        $js = (string) file_get_contents(public_path('js/atalhos.js'));
        $this->assertStringContainsString('localStorage', $js);
        $this->assertStringNotContainsString('fetch(', $js);
        $this->assertStringNotContainsString('XMLHttpRequest', $js);
    }
}
