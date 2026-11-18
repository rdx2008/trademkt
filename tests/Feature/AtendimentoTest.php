<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Admin;
use App\Models\Chamado;
use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

/** Fase 10 (10.9c): central de atendimento (chamados da empresa ao fornecedor). */
class AtendimentoTest extends TestCase
{
    private Tenant $a;

    private Tenant $b;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarCliente('alfa');
        $this->b = $this->criarCliente('beta', 'agencia');
        $this->adminA = $this->a->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
    }

    public function test_empresa_abre_chamado_e_conversa_com_o_fornecedor(): void
    {
        $this->actingAs($this->adminA)->get('http://alfa.test/atendimento')->assertOk()->assertSee('Novo chamado');
        $this->post('http://alfa.test/atendimento', ['assunto' => 'Como importar PDVs?', 'categoria' => 'duvida', 'mensagem' => 'Preciso de ajuda com a planilha.'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $c = Chamado::sole();
        $this->assertSame(['alfa', 'aberto'], [$c->tenant_id, $c->situacao]);
        $this->get("http://alfa.test/atendimento/{$c->id}")->assertOk()->assertSee('Preciso de ajuda com a planilha.')->assertSee('Aguardando o fornecedor');

        // O fornecedor responde pelo painel central.
        $this->app['auth']->forgetGuards();
        $this->actingAs(Admin::create(['nome' => 'Rodolfo', 'email' => 'r@t.com', 'password' => 'senha1234']), 'admin');
        $this->get('http://central.test/chamados')->assertOk()->assertSee('Como importar PDVs?')->assertSee('Alfa');
        $this->post("http://central.test/chamados/{$c->id}/responder", ['mensagem' => 'Use o modelo em Cadastros.', 'situacao' => 'respondido'])->assertRedirect();
        $this->assertSame('respondido', $c->fresh()->situacao);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->adminA)->get("http://alfa.test/atendimento/{$c->id}")->assertSee('Use o modelo em Cadastros.')->assertSee('Fornecedor');
        $this->post("http://alfa.test/atendimento/{$c->id}/responder", ['mensagem' => 'Obrigado!'])->assertRedirect();
        $this->assertSame('aberto', $c->fresh()->situacao, 'nova mensagem da empresa reabre');

        $this->patch("http://alfa.test/atendimento/{$c->id}/fechar")->assertRedirect();
        $this->assertSame('fechado', $c->fresh()->situacao);
        $this->post("http://alfa.test/atendimento/{$c->id}/responder", ['mensagem' => 'Mais uma'])->assertStatus(409);
    }

    public function test_empresa_ve_so_os_proprios_chamados(): void
    {
        $this->actingAs($this->adminA)->post('http://alfa.test/atendimento', ['assunto' => 'Chamado da Alfa', 'categoria' => 'problema', 'mensagem' => 'x']);
        $c = Chamado::sole();

        $adminB = $this->b->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
        $this->app['auth']->forgetGuards();
        $this->actingAs($adminB)->get('http://beta.test/atendimento')->assertOk()->assertDontSee('Chamado da Alfa');
        $this->get("http://beta.test/atendimento/{$c->id}")->assertNotFound();
        $this->post("http://beta.test/atendimento/{$c->id}/responder", ['mensagem' => 'invasão'])->assertNotFound();
        $this->patch("http://beta.test/atendimento/{$c->id}/fechar")->assertNotFound();
    }

    public function test_validacao_permissao_e_painel_central_protegido(): void
    {
        $this->actingAs($this->adminA);
        $this->post('http://alfa.test/atendimento', ['assunto' => '', 'categoria' => 'x', 'mensagem' => ''])->assertSessionHasErrors(['assunto', 'categoria', 'mensagem']);

        $representante = $this->criarUsuario($this->a, Perfil::Representante, ['industria_id' => 1, 'email' => 'rep@alfa.test']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($representante)->get('http://alfa.test/atendimento')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->get('http://central.test/chamados')->assertRedirect();
        $this->assertSame(0, Chamado::count());
    }
}
