<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/** APK do promotor por link na página do sistema (sem loja de aplicativos). */
class AplicativoAndroidTest extends TestCase
{
    private const URL = 'http://central.test';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function painel(): string
    {
        return self::URL.'/'.config('institucional.acesso').'/aplicativo';
    }

    private function apk(bool $comManifesto = true, string $nome = 'app-release.apk'): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'apk');
        $zip = new ZipArchive;
        $zip->open($caminho, ZipArchive::OVERWRITE);
        $zip->addFromString($comManifesto ? 'AndroidManifest.xml' : 'leia.txt', 'conteudo');
        $zip->addFromString('classes.dex', str_repeat('x', 100));
        $zip->close();

        return new UploadedFile($caminho, $nome, 'application/vnd.android.package-archive', null, true);
    }

    private function admin(): void
    {
        $this->actingAs(Admin::create(['nome' => 'Rodolfo', 'email' => 'r@test.com', 'password' => 'senha1234']), 'admin');
    }

    public function test_sem_apk_a_pagina_diz_em_breve_e_o_download_nao_existe(): void
    {
        $this->get(self::URL.'/app')->assertOk()->assertSee('em breve');
        $this->get(self::URL.'/app/promotor.apk')->assertNotFound();
        $this->get(self::URL.'/')->assertOk()->assertDontSee('Baixar o app Android');
    }

    public function test_so_super_admin_publica(): void
    {
        $this->get($this->painel())->assertRedirect();
        $this->post($this->painel(), ['versao' => '1.0.0', 'arquivo' => $this->apk()])->assertRedirect();
        $this->get(self::URL.'/app/promotor.apk')->assertNotFound();
    }

    public function test_publica_e_a_pagina_entrega_o_apk(): void
    {
        $this->admin();
        $this->post($this->painel(), ['versao' => '1.2.0', 'arquivo' => $this->apk()])
            ->assertRedirect($this->painel())->assertSessionHas('status');
        $this->get($this->painel())->assertOk()->assertSee('1.2.0');

        $this->app['auth']->forgetGuards();
        $this->get(self::URL.'/app')->assertOk()->assertSee('Versão 1.2.0')->assertSee('Baixar o app (Android)');
        $resposta = $this->get(self::URL.'/app/promotor.apk')->assertOk();
        $this->assertSame('application/vnd.android.package-archive', $resposta->headers->get('Content-Type'));
        $this->assertStringContainsString('promotor.apk', (string) $resposta->headers->get('Content-Disposition'));
        $this->get(self::URL.'/')->assertOk()->assertSee('Baixar o app Android')->assertSee(self::URL.'/app', false);
    }

    public function test_recusa_arquivo_que_nao_e_apk(): void
    {
        $this->admin();
        $this->post($this->painel(), ['versao' => '1.0.0', 'arquivo' => $this->apk(false)])->assertSessionHasErrors('arquivo');
        $this->post($this->painel(), ['versao' => '1.0.0', 'arquivo' => $this->apk(true, 'app.zip')])->assertSessionHasErrors('arquivo');
        $this->post($this->painel(), ['versao' => '<script>', 'arquivo' => $this->apk()])->assertSessionHasErrors('versao');
        $this->get(self::URL.'/app/promotor.apk')->assertNotFound();
    }

    public function test_nova_versao_substitui_e_remover_volta_para_em_breve(): void
    {
        $this->admin();
        $this->post($this->painel(), ['versao' => '1.0.0', 'arquivo' => $this->apk()]);
        $this->post($this->painel(), ['versao' => '1.1.0', 'arquivo' => $this->apk()]);
        $this->get(self::URL.'/app')->assertSee('Versão 1.1.0')->assertDontSee('Versão 1.0.0');

        $this->delete($this->painel())->assertRedirect();
        $this->get(self::URL.'/app')->assertSee('em breve');
        $this->get(self::URL.'/app/promotor.apk')->assertNotFound();
    }
}
