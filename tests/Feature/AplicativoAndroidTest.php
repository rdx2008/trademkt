<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Services\ConteudoInstitucional;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Link de download do app Android (hospedado fora) nas configurações do site e na landing page. */
class AplicativoAndroidTest extends TestCase
{
    private const URL = 'http://central.test';

    private const LINK = 'https://arquivos.exemplo.com.br/promotor-1.2.0.apk';

    private function publicar(string $link): TestResponse
    {
        $conteudo = ConteudoInstitucional::padrao();
        unset($conteudo['imagens']);
        $conteudo['app_android_url'] = $link;

        return $this->put(self::URL.'/'.config('institucional.acesso').'/site', ['conteudo' => $conteudo, 'acao' => 'publicar']);
    }

    public function test_sem_link_a_landing_nao_mostra_o_download(): void
    {
        $this->get(self::URL.'/')->assertOk()->assertDontSee('Baixar o app Android');
    }

    public function test_link_nas_configuracoes_aparece_na_landing(): void
    {
        $this->actingAs(Admin::create(['nome' => 'R', 'email' => 'r@test.com', 'password' => 'senha1234']), 'admin');
        $this->get(self::URL.'/'.config('institucional.acesso').'/site')->assertOk()->assertSee('Link de download do app Android');
        $this->publicar(self::LINK)->assertRedirect();

        $this->app['auth']->forgetGuards();
        $this->get(self::URL.'/')->assertOk()->assertSee('Baixar o app Android')->assertSee(self::LINK, false);
    }

    public function test_link_precisa_ser_https(): void
    {
        $this->actingAs(Admin::create(['nome' => 'R', 'email' => 'r@test.com', 'password' => 'senha1234']), 'admin');
        $this->publicar('http://inseguro.com/app.apk')->assertSessionHasErrors('conteudo.app_android_url');
        $this->publicar('javascript:alert(1)')->assertSessionHasErrors('conteudo.app_android_url');
    }
}
