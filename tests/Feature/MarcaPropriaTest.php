<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Admin;
use App\Models\Configuracao;
use App\Models\Industria;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marca\Aparencia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Marca própria (white label): nome, cor e logos por instalação, liberados por padrão.
 */
class MarcaPropriaTest extends TestCase
{
    private const URL = 'http://gaboardi.test';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->criarCliente('gaboardi');
    }

    private function admin(?Tenant $tenant = null): User
    {
        return ($tenant ?? $this->tenant)->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->first());
    }

    private function salvar(array $dados = []): void
    {
        $this->actingAs($this->admin())
            ->put(self::URL.'/configuracoes/aparencia', $dados)
            ->assertSessionHasNoErrors()
            ->assertRedirect(self::URL.'/configuracoes/aparencia');
    }

    // ------------------------------------------------------------------ cores

    public function test_normaliza_e_valida_cores_e_contraste(): void
    {
        $this->assertSame('#aabbcc', Aparencia::normalizarCor('ABC'));
        $this->assertSame('#0f766e', Aparencia::normalizarCor(' #0F766E '));
        $this->assertSame('#0f766e', Aparencia::normalizarCor('0f766e'));
        $this->assertNull(Aparencia::normalizarCor('azul'));
        $this->assertNull(Aparencia::normalizarCor('#12345'));
        $this->assertNull(Aparencia::normalizarCor(''));

        $this->assertEqualsWithDelta(21.0, Aparencia::contraste('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, Aparencia::contraste('#ffffff', '#ffffff'), 0.01);
        $this->assertGreaterThan(Aparencia::CONTRASTE_MINIMO, Aparencia::contraste(Aparencia::COR_PADRAO, '#ffffff'));
        $this->assertLessThan(Aparencia::CONTRASTE_MINIMO, Aparencia::contraste('#ffff00', '#ffffff'));

        $this->assertSame('#808080', Aparencia::misturar('#ffffff', '#000000', 0.5), 'meio caminho entre branco e preto');
        $this->assertSame('#000000', Aparencia::misturar('#123456', '#000000', 1.0));
    }

    // ----------------------------------------------------------------- padrão

    public function test_por_padrao_mostra_o_nome_do_cliente_sem_citar_trade_promo(): void
    {
        $this->actingAs($this->admin())->get(self::URL.'/painel')
            ->assertOk()
            ->assertSee('<title>Visão da operação · Gaboardi</title>', false)
            ->assertSee('tema/images/favicon.png', false)
            ->assertDontSee('marca/tema.css', false)
            ->assertDontSee('Trade Promo')
            ->assertDontSee('TRADE');

        $this->app['auth']->forgetGuards();
        $this->get(self::URL.'/entrar')->assertOk()->assertSee('marca-entrada', false)->assertDontSee('Trade Promo');
    }

    public function test_marca_propria_esta_liberada_por_padrao_e_o_menu_tem_o_item(): void
    {
        $this->assertTrue($this->tenant->whiteLabelAtivo());
        $this->assertFalse($this->tenant->mostrarCreditos());

        $this->actingAs($this->admin())->get(self::URL.'/painel')->assertSee('Aparência');
        $this->get(self::URL.'/configuracoes/aparencia')->assertOk()
            ->assertSee('Aparência da sua instalação')
            ->assertSee('Cor principal')
            ->assertSee('Logo para fundo claro')
            ->assertSee('SVG não é aceito');
    }

    // --------------------------------------------------------------- nome e cor

    public function test_admin_define_nome_e_cor_e_todas_as_telas_passam_a_usa_los(): void
    {
        $this->salvar(['nome' => 'Fósforos Gaboardi', 'cor' => '#0F766E']);

        $this->tenant->run(function () {
            $this->assertSame('Fósforos Gaboardi', Configuracao::where('chave', 'marca.nome')->value('valor'));
            $this->assertSame('#0f766e', Configuracao::where('chave', 'marca.cor')->value('valor'));
        });

        $pagina = $this->get(self::URL.'/painel')->assertOk()
            ->assertSee('<title>Visão da operação · Fósforos Gaboardi</title>', false)
            ->assertSee('Fósforos Gaboardi')
            ->assertSee('marca/tema.css?v=', false);

        preg_match('#href="([^"]*marca/tema\.css\?v=[^"]*)"#', $pagina->getContent(), $achou);
        $css = $this->get($achou[1])->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8');
        $css->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        $corpo = $css->getContent();
        $this->assertStringContainsString('--primary: #0f766e;', $corpo);
        $this->assertStringContainsString('--rgba-primary-1: rgba(15, 118, 110, 0.1);', $corpo);
        $this->assertStringContainsString('.btn-primary', $corpo);
        $this->assertStringContainsString('[data-sidebarbg=color_3] .deznav', $corpo);
        $this->assertStringNotContainsString('#222fb9', $corpo);

        // Sem a versão vigente na URL o navegador sempre revalida.
        $this->get(self::URL.'/marca/tema.css')->assertHeader('Cache-Control', 'max-age=0, must-revalidate, public');

        // A tela de entrada (sem login) também usa a marca.
        $this->app['auth']->forgetGuards();
        $this->get(self::URL.'/entrar')->assertSee('Fósforos Gaboardi')->assertSee('marca/tema.css?v=', false);
    }

    public function test_graficos_recebem_a_cor_da_marca_por_variavel_css(): void
    {
        $this->actingAs($this->admin())->get(self::URL.'/bi')->assertOk()->assertSee('&quot;cores&quot;:[&quot;primary&quot;]', false);
        $this->assertStringContainsString("'primary'", file_get_contents(public_path('js/graficos.js')));
    }

    public function test_cor_clara_demais_ou_invalida_e_recusada(): void
    {
        $this->actingAs($this->admin());

        $this->put(self::URL.'/configuracoes/aparencia', ['cor' => '#ffff00'])->assertSessionHasErrors('cor');
        $this->put(self::URL.'/configuracoes/aparencia', ['cor' => 'azul'])->assertSessionHasErrors('cor');
        $this->put(self::URL.'/configuracoes/aparencia', ['cor' => '#12'])->assertSessionHasErrors('cor');

        $this->tenant->run(fn () => $this->assertSame(0, Configuracao::count()));
    }

    public function test_cor_em_branco_ou_igual_ao_padrao_nao_gera_folha_propria(): void
    {
        $this->salvar(['nome' => '', 'cor' => '#222fb9']);

        $this->tenant->run(fn () => $this->assertSame(0, Configuracao::count()));
        $this->get(self::URL.'/painel')->assertDontSee('marca/tema.css', false);
    }

    // ------------------------------------------------------------------- logos

    public function test_logos_sao_recodificados_servidos_publicamente_e_aparecem_nas_telas(): void
    {
        $this->salvar([
            'logo_claro' => UploadedFile::fake()->image('claro.png', 1200, 400),
            'logo_escuro' => UploadedFile::fake()->image('escuro.jpg', 300, 100),
            'icone' => UploadedFile::fake()->image('icone.webp', 512, 512),
        ]);

        $caminhos = $this->tenant->run(fn () => Configuracao::whereIn('chave', ['marca.logo_claro', 'marca.logo_escuro', 'marca.icone'])->pluck('valor', 'chave')->all());
        $this->assertCount(3, $caminhos);
        foreach ($caminhos as $caminho) {
            $this->assertStringStartsWith('marca/', $caminho);
            $this->assertStringEndsWith('.png', $caminho);
        }

        // O arquivo foi reduzido ao limite e continua PNG válido.
        $this->tenant->run(function () use ($caminhos) {
            $info = getimagesizefromstring(Storage::disk('local')->get($caminhos['marca.logo_claro']));
            $this->assertSame(IMAGETYPE_PNG, $info[2]);
            $this->assertLessThanOrEqual(480, $info[0]);
            $this->assertLessThanOrEqual(160, $info[1]);
            $icone = getimagesizefromstring(Storage::disk('local')->get($caminhos['marca.icone']));
            $this->assertLessThanOrEqual(256, $icone[0]);
        });

        // Servidos sem login: a tela de entrada precisa deles.
        $this->app['auth']->forgetGuards();
        foreach (['claro', 'escuro', 'icone'] as $tipo) {
            $this->get(self::URL."/marca/{$tipo}")->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        }
        $this->get(self::URL.'/entrar')->assertOk()->assertSee('logo-para-claro', false)->assertSee('logo-para-escuro', false)->assertSee('marca/claro?v=', false);

        $this->actingAs($this->admin())->get(self::URL.'/painel')
            ->assertSee('marca/escuro?v=', false)
            ->assertSee('marca/icone?v=', false)
            ->assertDontSee('inicial-marca', false);
    }

    public function test_um_unico_logo_serve_para_os_dois_fundos(): void
    {
        $this->salvar(['logo_claro' => UploadedFile::fake()->image('logo.png', 400, 120)]);

        $this->actingAs($this->admin())->get(self::URL.'/painel')->assertSee('marca/claro?v=', false);
        $this->get(self::URL.'/marca/escuro')->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->get(self::URL.'/entrar')->assertSee('logo-para-escuro', false);
    }

    public function test_trocar_e_remover_logo_apaga_o_arquivo_antigo(): void
    {
        $this->salvar(['logo_claro' => UploadedFile::fake()->image('a.png', 200, 80)]);
        $primeiro = $this->tenant->run(fn () => Configuracao::where('chave', 'marca.logo_claro')->value('valor'));

        $this->salvar(['logo_claro' => UploadedFile::fake()->image('b.png', 200, 80)]);
        $segundo = $this->tenant->run(fn () => Configuracao::where('chave', 'marca.logo_claro')->value('valor'));
        $this->assertNotSame($primeiro, $segundo);
        $this->tenant->run(function () use ($primeiro, $segundo) {
            $this->assertFalse(Storage::disk('local')->exists($primeiro));
            $this->assertTrue(Storage::disk('local')->exists($segundo));
        });

        $this->salvar(['remover' => ['claro']]);
        $this->tenant->run(function () use ($segundo) {
            $this->assertFalse(Storage::disk('local')->exists($segundo));
            $this->assertNull(Configuracao::where('chave', 'marca.logo_claro')->value('valor'));
        });
        $this->get(self::URL.'/marca/claro')->assertNotFound();
    }

    public function test_so_imagens_png_jpg_webp_sao_aceitas_e_svg_e_recusado(): void
    {
        $this->actingAs($this->admin());

        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->put(self::URL.'/configuracoes/aparencia', ['logo_claro' => $svg])->assertSessionHasErrors('logo_claro');

        $disfarcado = UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1; ?>');
        $this->put(self::URL.'/configuracoes/aparencia', ['logo_claro' => $disfarcado])->assertSessionHasErrors('logo_claro');

        $grande = UploadedFile::fake()->create('grande.png', 3000, 'image/png');
        $this->put(self::URL.'/configuracoes/aparencia', ['logo_claro' => $grande])->assertSessionHasErrors('logo_claro');

        $pequena = UploadedFile::fake()->image('mini.png', 8, 8);
        $this->put(self::URL.'/configuracoes/aparencia', ['icone' => $pequena])->assertSessionHasErrors('icone');

        $this->tenant->run(fn () => $this->assertSame(0, Configuracao::count()));
    }

    public function test_restaurar_padrao_apaga_configuracoes_e_arquivos(): void
    {
        $this->salvar(['nome' => 'Outro nome', 'cor' => '#b91c1c', 'logo_claro' => UploadedFile::fake()->image('a.png', 200, 80)]);
        $arquivo = $this->tenant->run(fn () => Configuracao::where('chave', 'marca.logo_claro')->value('valor'));

        $this->delete(self::URL.'/configuracoes/aparencia')->assertRedirect(self::URL.'/configuracoes/aparencia')
            ->assertSessionHas('status', 'Aparência restaurada para o padrão.');

        $this->tenant->run(function () use ($arquivo) {
            $this->assertSame(0, Configuracao::count());
            $this->assertFalse(Storage::disk('local')->exists($arquivo));
        });
        $this->get(self::URL.'/painel')->assertSee('<title>Visão da operação · Gaboardi</title>', false)->assertDontSee('marca/tema.css', false);
    }

    // ------------------------------------------------------ isolamento e permissão

    public function test_a_marca_de_um_cliente_nao_aparece_em_outro(): void
    {
        $outro = $this->criarCliente('abc');
        $this->salvar(['nome' => 'Marca Gaboardi', 'cor' => '#b91c1c', 'logo_claro' => UploadedFile::fake()->image('a.png', 200, 80)]);

        Aparencia::esquecer();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin($outro))->get('http://abc.test/painel')->assertOk()
            ->assertSee('<title>Visão da operação · Abc</title>', false)
            ->assertDontSee('Marca Gaboardi')
            ->assertDontSee('marca/tema.css', false);
        $this->get('http://abc.test/marca/claro')->assertNotFound();
        $this->get('http://abc.test/marca/tema.css')->assertSee('--primary: #222fb9;', false);

        $this->tenant->run(fn () => $this->assertSame('#b91c1c', Configuracao::where('chave', 'marca.cor')->value('valor')));
    }

    public function test_so_o_admin_da_instalacao_edita_a_aparencia(): void
    {
        $marca = $this->tenant->run(fn () => Industria::first());
        $gerente = $this->criarUsuario($this->tenant, Perfil::GerenteTrade, ['industria_id' => $marca->id]);

        $this->actingAs($gerente);
        $this->get(self::URL.'/configuracoes/aparencia')->assertForbidden();
        $this->put(self::URL.'/configuracoes/aparencia', ['cor' => '#b91c1c'])->assertForbidden();
        $this->delete(self::URL.'/configuracoes/aparencia')->assertForbidden();
        $this->get(self::URL.'/painel')->assertDontSee('Aparência');

        $this->app['auth']->forgetGuards();
        $this->get(self::URL.'/configuracoes/aparencia')->assertRedirect(self::URL.'/entrar');
    }

    // ------------------------------------------------- controles do fornecedor

    public function test_fornecedor_pode_desligar_a_marca_propria_do_cliente(): void
    {
        $this->salvar(['nome' => 'Nome próprio', 'cor' => '#b91c1c']);

        $this->tenant->white_label = false;
        $this->tenant->save();
        tenancy()->end(); // no teste o mesmo processo atende as duas requisições; força reler o cliente
        Aparencia::esquecer();
        $this->app['auth']->forgetGuards();

        $this->actingAs($this->admin())->get(self::URL.'/painel')->assertOk()
            ->assertSee('<title>Visão da operação · Gaboardi</title>', false)
            ->assertDontSee('Nome próprio')
            ->assertDontSee('marca/tema.css', false)
            ->assertDontSee('href="'.self::URL.'/configuracoes/aparencia"', false);
        $this->get(self::URL.'/configuracoes/aparencia')->assertForbidden();
        $this->put(self::URL.'/configuracoes/aparencia', ['cor' => '#0f766e'])->assertForbidden();

        // As escolhas ficam guardadas: ligando de novo, voltam.
        $this->tenant->white_label = true;
        $this->tenant->save();
        tenancy()->end();
        Aparencia::esquecer();
        $this->get(self::URL.'/painel')->assertSee('Nome próprio');
    }

    public function test_creditos_do_fornecedor_so_aparecem_quando_ligados(): void
    {
        $this->actingAs($this->admin())->get(self::URL.'/painel')->assertDontSee('Desenvolvido por');

        $this->tenant->mostrar_creditos = true;
        $this->tenant->save();
        tenancy()->end();
        Aparencia::esquecer();

        $this->get(self::URL.'/painel')->assertSee('Desenvolvido por')->assertSee('Trade Promo');
    }

    public function test_super_admin_controla_marca_e_creditos_do_cliente(): void
    {
        $central = Admin::create(['nome' => 'Rodolfo', 'email' => 'r@mastervps.test', 'password' => 'senha1234']);
        $this->actingAs($central, 'admin');

        $this->get('http://central.test/clientes')->assertOk()->assertSee('Marca');
        $this->get("http://central.test/clientes/{$this->tenant->id}/marca")->assertOk()
            ->assertSee('Liberar marca própria')
            ->assertSee('css/app.css', false)
            ->assertDontSee('tema/css/style.css', false);

        $this->put("http://central.test/clientes/{$this->tenant->id}/marca", ['mostrar_creditos' => '1'])
            ->assertRedirect('http://central.test/clientes');

        $atualizado = Tenant::find($this->tenant->id);
        $this->assertFalse($atualizado->whiteLabelAtivo(), 'desmarcado no formulário = desligado');
        $this->assertTrue($atualizado->mostrarCreditos());

        $this->put("http://central.test/clientes/{$this->tenant->id}/marca", ['white_label' => '1'])->assertRedirect();
        $this->assertTrue(Tenant::find($this->tenant->id)->whiteLabelAtivo());
        $this->assertFalse(Tenant::find($this->tenant->id)->mostrarCreditos());
    }
}
