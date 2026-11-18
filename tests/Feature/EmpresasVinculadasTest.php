<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vinculo;
use App\Services\Rede\Operacao;
use Tests\TestCase;

/** Fase 9, item 9.4: pedido de vínculo por CNPJ entre empresas que já usam o sistema. */
class EmpresasVinculadasTest extends TestCase
{
    private const IND = 'http://gaboardi.test';

    private const AG = 'http://sul.test';

    private Tenant $industria;

    private Tenant $agencia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->industria = $this->criarCliente('gaboardi');
        $this->agencia = $this->criarCliente('sul', 'agencia');
        tenancy()->end();
        $this->industria->update(['cnpj' => '11222333000181']);
        $this->agencia->update(['cnpj' => '45723174000110']);
    }

    private function admin(Tenant $t): User
    {
        return $t->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
    }

    private function como(Tenant $t): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin($t));
    }

    public function test_agencia_pede_e_industria_aceita_com_permissao_de_catalogo(): void
    {
        $this->como($this->agencia);
        $this->get(self::AG.'/empresas-vinculadas')->assertOk()->assertSee('Pedir vínculo a uma indústria');
        $this->post(self::AG.'/empresas-vinculadas/pedidos', ['cnpj' => '11.222.333/0001-81', 'relacao' => 'prospect'])->assertRedirect();

        $v = Vinculo::sole();
        $this->assertSame([Vinculo::PENDENTE, false, 'agencia', 'prospect'], [$v->status, $v->ativo, $v->origem === 'agencia' ? 'agencia' : $v->origem, $v->relacao]);
        $this->assertTrue(app(Operacao::class)->industriasDe($this->agencia)->isEmpty(), 'pendente não libera a operação');
        $this->assertFalse($this->industria->run(fn () => Agencia::where('agencia_tenant_id', 'sul')->exists()));
        $this->get(self::AG.'/empresas-vinculadas')->assertSee('Aguardando resposta');

        // Quem pediu não aceita o próprio pedido.
        $this->patch(self::AG."/empresas-vinculadas/pedidos/{$v->id}/aceitar")->assertForbidden();

        $this->como($this->industria);
        $this->get(self::IND.'/empresas-vinculadas')->assertOk()->assertSee('Pedidos recebidos')->assertSee('Sul');
        $this->patch(self::IND."/empresas-vinculadas/pedidos/{$v->id}/aceitar", [])->assertRedirect();

        $v->refresh();
        $this->assertSame(Vinculo::ATIVO, $v->status);
        $this->assertTrue($v->ativo);
        $this->assertFalse($v->agenciaMantemCatalogo(), 'a indústria desmarcou a permissão de catálogo');
        $this->assertTrue($this->industria->run(fn () => Agencia::where('agencia_tenant_id', 'sul')->where('ativo', true)->exists()));
        $this->assertSame(['gaboardi'], app(Operacao::class)->industriasDe($this->agencia)->pluck('id')->all());
    }

    public function test_industria_pede_e_agencia_recusa(): void
    {
        $this->como($this->industria);
        $this->post(self::IND.'/empresas-vinculadas/pedidos', ['cnpj' => '45.723.174/0001-10'])->assertRedirect();
        $v = Vinculo::sole();
        $this->assertSame('industria', $v->origem);

        $this->como($this->agencia);
        $this->patch(self::AG."/empresas-vinculadas/pedidos/{$v->id}/recusar")->assertRedirect();
        $this->assertSame(Vinculo::RECUSADO, $v->fresh()->status);
        $this->assertFalse($v->fresh()->ativo);

        // Depois de recusado, dá para pedir de novo.
        $this->como($this->industria);
        $this->post(self::IND.'/empresas-vinculadas/pedidos', ['cnpj' => '45723174000110'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(Vinculo::PENDENTE, $v->fresh()->status);
    }

    public function test_cnpj_desconhecido_repetido_ou_ja_vinculado(): void
    {
        $this->como($this->industria);
        $this->post(self::IND.'/empresas-vinculadas/pedidos', ['cnpj' => '12.345.678/0001-95'])->assertSessionHasErrors('cnpj');
        $this->post(self::IND.'/empresas-vinculadas/pedidos', ['cnpj' => '123'])->assertSessionHasErrors('cnpj');
        $this->post(self::IND.'/empresas-vinculadas/pedidos', ['cnpj' => '11222333000181'])->assertSessionHasErrors('cnpj'); // a própria indústria não é agência
        $this->post(self::IND.'/empresas-vinculadas/pedidos', ['cnpj' => '45723174000110'])->assertSessionHasNoErrors();
        $this->post(self::IND.'/empresas-vinculadas/pedidos', ['cnpj' => '45723174000110'])->assertSessionHasErrors('cnpj');
        $this->assertSame(1, Vinculo::count());
    }

    public function test_quem_pediu_cancela_e_terceiros_nao_mexem(): void
    {
        $this->como($this->industria);
        $this->post(self::IND.'/empresas-vinculadas/pedidos', ['cnpj' => '45723174000110']);
        $v = Vinculo::sole();

        $outra = $this->criarCliente('norte', 'agencia');
        tenancy()->end();
        $this->como($outra);
        $this->patch("http://norte.test/empresas-vinculadas/pedidos/{$v->id}/aceitar")->assertNotFound();

        $supervisor = $this->criarUsuario($this->agencia, Perfil::Supervisor, ['agencia_id' => 1]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($supervisor)->get(self::AG.'/empresas-vinculadas')->assertForbidden();

        $this->como($this->industria);
        $this->delete(self::IND."/empresas-vinculadas/pedidos/{$v->id}")->assertRedirect();
        $this->assertSame(0, Vinculo::count());
    }
}
