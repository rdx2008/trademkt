<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Services\ConteudoInstitucional;
use Tests\TestCase;

/** O app do promotor pergunta a versão mínima e a atual, e recebe o link de download da landing. */
class VersaoDoAppTest extends TestCase
{
    private const URL = 'http://central.test/api/v1/app';

    public function test_devolve_as_versoes_configuradas_sem_link_quando_a_landing_nao_tem(): void
    {
        config(['trade.app.versao_minima' => '0.3.0', 'trade.app.versao_atual' => '0.4.1']);

        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('versao_minima', '0.3.0')
            ->assertJsonPath('versao_atual', '0.4.1')
            ->assertJsonPath('download_url', null);
    }

    public function test_traz_o_link_de_download_publicado_na_landing(): void
    {
        $this->actingAs(Admin::create(['nome' => 'R', 'email' => 'r@test.com', 'password' => 'senha1234']), 'admin');
        $conteudo = ConteudoInstitucional::padrao();
        unset($conteudo['imagens']);
        $conteudo['app_android_url'] = 'https://arquivos.exemplo.com.br/promotor-1.2.0.apk';
        $this->put('http://central.test/'.config('institucional.acesso').'/site', ['conteudo' => $conteudo, 'acao' => 'publicar'])->assertRedirect();
        auth('admin')->logout();

        $this->getJson(self::URL)->assertOk()->assertJsonPath('download_url', 'https://arquivos.exemplo.com.br/promotor-1.2.0.apk');
    }

    public function test_padrao_nao_exige_atualizacao(): void
    {
        $this->getJson(self::URL)->assertOk()->assertJsonPath('versao_minima', '0.0.0');
    }
}
