<?php

namespace Tests\Feature;

use App\Enums\ModoEmpresa;
use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Http\Middleware\DominioCentral;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Rede\Autocadastro;
use App\Support\DominiosCentrais;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/** Endereços: prefixo escolhido pela empresa (`nome.base`), landing em um domínio e painel em outro. */
class DominiosDaRedeTest extends TestCase
{
    private function dados(array $extra = []): array
    {
        return array_merge(['nome' => 'Agência Teste', 'cnpj' => '45.723.174/0001-10', 'subdominio' => 'agenciateste', 'admin_nome' => 'Ana', 'admin_email' => 'ana@agenciateste.com.br'], $extra);
    }

    public function test_prefixo_escolhido_vira_endereco_codigo_do_app_e_identificador(): void
    {
        config(['trade.dominio_clientes' => 'bra.net.br']);
        $industria = $this->criarCliente('marca');
        tenancy()->end();

        $r = app(Autocadastro::class)->criar($industria, TipoInstalacao::Agencia, ModoEmpresa::Propria, $this->dados(['subdominio' => ' Minha-Agencia ']));

        $nova = Tenant::find($r['tenant']->id);
        $this->assertSame('minha_agencia', $nova->id);
        $this->assertSame('minha-agencia', $nova->codigo);
        $this->assertSame('minha-agencia.bra.net.br', $nova->domains()->value('domain'));
        $this->assertStringStartsWith('http://minha-agencia.bra.net.br/convite/', $r['convite']);
    }

    public function test_prefixo_invalido_reservado_ou_em_uso_e_recusado(): void
    {
        $existente = $this->criarCliente('marca');
        $this->criarCliente('ocupada', 'agencia');
        tenancy()->end();
        $servico = app(Autocadastro::class);

        foreach (['ab', '1agencia', 'agencia_x', '-agencia', 'agencia-', 'com espaco', str_repeat('a', 31), '', 'trade', 'www', 'admin', 'ocupada'] as $ruim) {
            try {
                $servico->criar($existente, TipoInstalacao::Agencia, ModoEmpresa::Propria, $this->dados(['subdominio' => $ruim]));
                $this->fail("deveria recusar '{$ruim}'");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('subdominio', $e->errors(), $ruim);
            }
        }
        $this->assertSame(2, Tenant::count(), 'nada foi criado');

        // O mesmo prefixo não vale duas vezes.
        $servico->criar($existente, TipoInstalacao::Agencia, ModoEmpresa::Propria, $this->dados(['subdominio' => 'unica']));
        $this->expectException(ValidationException::class);
        $servico->criar($existente, TipoInstalacao::Agencia, ModoEmpresa::Propria, $this->dados(['subdominio' => 'unica', 'cnpj' => '11.222.333/0001-81']));
    }

    public function test_formulario_da_agencia_mostra_o_dominio_base_e_valida_o_prefixo(): void
    {
        config(['trade.dominio_clientes' => 'bra.net.br']);
        $industria = $this->criarCliente('gaboardi');
        $admin = $industria->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
        tenancy()->end();

        $this->actingAs($admin)->get('http://gaboardi.test/agencias/nova')->assertOk()->assertSee('Nome do endereço')->assertSee('.bra.net.br');
        $this->post('http://gaboardi.test/agencias', ['tipo_agencia' => 'externa'] + $this->dados(['subdominio' => 'www']))->assertSessionHasErrors('subdominio');
        $this->post('http://gaboardi.test/agencias', ['tipo_agencia' => 'externa'] + $this->dados(['subdominio' => 'boaagencia']))->assertSessionHasNoErrors();
        $this->assertTrue(Tenant::where('codigo', 'boaagencia')->exists());
    }

    // ------------------------------------------------------------ site x painel

    private function passar(string $host, string $area, ?string $site, string $caminho = '/'): mixed
    {
        config(['institucional.dominio_site' => $site]);
        $request = Request::create("http://{$host}{$caminho}");
        $request->setLaravelSession($this->app['session.store']);

        return app(DominioCentral::class)->handle($request, fn () => response('ok'), $area);
    }

    public function test_sem_dominio_de_site_tudo_responde_em_todo_dominio_central(): void
    {
        $this->assertSame('ok', $this->passar('trade.bra.net.br', 'site', null)->getContent());
        $this->assertSame('ok', $this->passar('bra.net.br', 'admin', null)->getContent());
    }

    public function test_landing_so_no_dominio_do_site_e_painel_so_nos_outros(): void
    {
        // Landing: no site passa; no painel a raiz leva ao login e o resto some.
        $this->assertSame('ok', $this->passar('bra.net.br', 'site', 'bra.net.br')->getContent());
        $resposta = $this->passar('trade.bra.net.br', 'site', 'bra.net.br');
        $this->assertTrue($resposta->isRedirect());
        $this->assertStringEndsWith('/'.config('institucional.acesso'), $resposta->headers->get('Location'));
        $this->expectException(NotFoundHttpException::class);
        $this->passar('trade.bra.net.br', 'site', 'bra.net.br', '/privacidade');
    }

    public function test_painel_nunca_responde_no_dominio_do_site_e_o_dominio_antigo_continua(): void
    {
        $this->assertSame('ok', $this->passar('trade.bra.net.br', 'admin', 'bra.net.br')->getContent());
        $this->assertSame('ok', $this->passar('trade.mastervps.online', 'admin', 'bra.net.br')->getContent(), 'o domínio antigo continua sendo painel');
        $this->expectException(NotFoundHttpException::class);
        $this->passar('BRA.net.br', 'admin', 'bra.net.br', '/'.config('institucional.acesso'));
    }

    public function test_so_registra_as_rotas_do_dominio_acessado_na_web(): void
    {
        $todos = ['bra.net.br', 'trade.bra.net.br', 'trade.mastervps.online'];
        $this->assertSame(['trade.bra.net.br'], DominiosCentrais::paraRegistrar('trade.bra.net.br', $todos, false));
        $this->assertSame(['bra.net.br'], DominiosCentrais::paraRegistrar('BRA.net.br', $todos, false));
        $this->assertSame($todos, DominiosCentrais::paraRegistrar('gaboardi.bra.net.br', $todos, false), 'domínio de cliente: lista inteira, sem escolher um');
        $this->assertSame($todos, DominiosCentrais::paraRegistrar(null, $todos, true), 'no console registra todos');
    }
}
