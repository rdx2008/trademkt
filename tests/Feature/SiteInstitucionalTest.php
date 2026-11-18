<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SiteContato;
use App\Models\SiteInstitucional;
use App\Services\ConteudoInstitucional;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SiteInstitucionalTest extends TestCase
{
    private function acesso(): string
    {
        return 'http://central.test/'.config('institucional.acesso');
    }

    private function autenticar(): void
    {
        $this->actingAs(Admin::create(['nome' => 'Editor', 'email' => 'editor@test.com', 'password' => 'senha1234']), 'admin');
    }

    private function dados(string $acao = 'salvar'): array
    {
        $dados = ConteudoInstitucional::padrao();
        unset($dados['imagens']);

        return ['conteudo' => $dados, 'acao' => $acao];
    }

    public function test_site_publico_nao_expoe_login_e_login_antigo_nao_responde(): void
    {
        $this->get('http://central.test/')->assertOk()->assertSee('Sua operação de trade')->assertDontSee('Entrar')->assertDontSee(config('institucional.acesso'))->assertDontSee('Materiais de PDV');
        $this->get('http://central.test/entrar')->assertNotFound();
        $this->get($this->acesso())->assertOk();
        $this->get('http://central.test/privacidade')->assertOk();
    }

    public function test_editor_e_previa_exigem_super_admin(): void
    {
        $this->get($this->acesso().'/site')->assertRedirect($this->acesso());
        $this->get($this->acesso().'/site/previa')->assertRedirect($this->acesso());
        $this->put($this->acesso().'/site', $this->dados('publicar'))->assertRedirect($this->acesso());
        $this->get($this->acesso().'/site/contatos')->assertRedirect($this->acesso());
    }

    public function test_edicao_rascunho_previa_publicacao_e_isolamento_da_versao_publica(): void
    {
        $this->autenticar();
        $this->get($this->acesso().'/site')->assertOk()->assertSee('Salvar rascunho');
        $dados = $this->dados();
        $dados['conteudo']['secoes']['hero']['titulo'] = 'Título ainda em rascunho';
        $this->put($this->acesso().'/site', $dados)->assertSessionHasNoErrors()->assertRedirect();
        $this->get('http://central.test/')->assertDontSee('Título ainda em rascunho');
        $this->get($this->acesso().'/site/previa')->assertOk()->assertSee('Título ainda em rascunho')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $dados['acao'] = 'publicar';
        $this->put($this->acesso().'/site', $dados)->assertSessionHasNoErrors();
        $this->get('http://central.test/')->assertSee('Título ainda em rascunho');
        $dados['acao'] = 'salvar';
        $dados['conteudo']['secoes']['hero']['titulo'] = 'Segunda edição';
        $this->put($this->acesso().'/site', $dados)->assertSessionHasNoErrors();
        $this->get('http://central.test/')->assertSee('Título ainda em rascunho')->assertDontSee('Segunda edição');
    }

    public function test_links_inseguros_cores_claras_e_uploads_invalidos_sao_rejeitados(): void
    {
        $this->autenticar();
        $dados = $this->dados();
        $dados['conteudo']['botao_url'] = 'javascript:alert(1)';
        $this->put($this->acesso().'/site', $dados)->assertSessionHasErrors('conteudo.botao_url');
        $dados = $this->dados();
        $dados['conteudo']['cor'] = '#ffffff';
        $this->put($this->acesso().'/site', $dados)->assertSessionHasErrors('conteudo.cor');
        $dados = $this->dados();
        $dados['uploads']['logo'] = UploadedFile::fake()->create('ataque.svg', 10, 'image/svg+xml');
        $this->put($this->acesso().'/site', $dados)->assertSessionHasErrors('uploads.logo');
    }

    public function test_textos_sao_escapados_e_secoes_ocultas_nao_sao_exibidas(): void
    {
        $this->autenticar();
        $dados = $this->dados('publicar');
        $dados['conteudo']['secoes']['hero']['titulo'] = '<script>alert(1)</script>';
        $dados['conteudo']['secoes']['publicos']['visivel'] = false;
        $this->put($this->acesso().'/site', $dados)->assertSessionHasNoErrors();
        $this->get('http://central.test/')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('Uma plataforma. Toda a operação.');
    }

    public function test_imagem_publicada_permanece_quando_removida_apenas_do_rascunho(): void
    {
        Storage::fake('local');
        $this->autenticar();
        $dados = $this->dados('publicar');
        $dados['uploads']['logo'] = UploadedFile::fake()->image('marca.png', 300, 100);
        $this->put($this->acesso().'/site', $dados)->assertSessionHasNoErrors();
        $nome = SiteInstitucional::find(1)->publicado['imagens']['logo'];
        $this->get('http://central.test/institucional/arquivos/'.$nome)->assertOk();
        $dados = $this->dados();
        $dados['remover_imagens']['logo'] = 1;
        $this->put($this->acesso().'/site', $dados)->assertSessionHasNoErrors();
        $this->get('http://central.test/')->assertSee($nome);
        Storage::disk('local')->assertExists('institucional/'.$nome);
        $this->get('http://central.test/institucional/arquivos/arquivo.php')->assertNotFound();
    }

    public function test_formulario_valida_dados_e_grava_pedido_no_painel(): void
    {
        $dados = ['nome' => 'Maria', 'email' => 'maria@empresa.com', 'empresa' => 'Empresa', 'perfil' => 'industria', 'mensagem' => 'Gostaria de conhecer a plataforma.', 'privacidade' => 1];
        $this->post('http://central.test/demonstracao', $dados)->assertSessionHasNoErrors()->assertRedirect('http://central.test#contato');
        $this->assertDatabaseHas('site_contatos', ['email' => 'maria@empresa.com', 'situacao' => 'novo']);
        $this->post('http://central.test/demonstracao', array_merge($dados, ['privacidade' => 0]))->assertSessionHasErrors('privacidade');
        $this->post('http://central.test/demonstracao', array_merge($dados, ['website' => 'spam']))->assertSessionHasErrors('website');
        $this->assertSame(1, SiteContato::count());
        $this->autenticar();
        $contato = SiteContato::first();
        $this->get($this->acesso().'/site/contatos')->assertOk()->assertSee('maria@empresa.com');
        $this->patch($this->acesso().'/site/contatos/'.$contato->id, ['situacao' => 'concluido'])->assertSessionHasNoErrors();
        $this->assertSame('concluido', $contato->fresh()->situacao);
        $this->delete($this->acesso().'/site/contatos/'.$contato->id)->assertRedirect();
        $this->assertDatabaseCount('site_contatos', 0);
    }

    public function test_limite_de_envios(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('http://central.test/demonstracao', []);
        }
        $this->post('http://central.test/demonstracao', [])->assertStatus(429);
    }

    public function test_formulario_nao_recebe_contatos_quando_secao_oculta(): void
    {
        $this->autenticar();
        $dados = $this->dados('publicar');
        $dados['conteudo']['secoes']['contato']['visivel'] = false;
        $this->put($this->acesso().'/site', $dados)->assertSessionHasNoErrors();
        $this->post('http://central.test/demonstracao', [])->assertNotFound();
    }

    public function test_site_e_dados_centrais_nao_sao_servidos_em_dominio_de_cliente(): void
    {
        $this->criarCliente();
        $this->get('http://gaboardi.test/'.config('institucional.acesso').'/site')->assertNotFound();
        $this->get('http://gaboardi.test/privacidade')->assertNotFound();
    }
}
