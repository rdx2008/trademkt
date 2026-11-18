<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Pdv;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cadastro de PDV: o CEP preenche o endereço e a latitude/longitude saem sozinhas, com a chave
 * do Google ou, sem chave, pelo OpenStreetMap.
 */
class CepELocalizacaoTest extends TestCase
{
    private const URL = 'http://gaboardi.test';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['trade.geocoding.google_key' => null, 'trade.geocoding.osm' => true]);
        $this->admin = $this->criarUsuario($this->criarCliente('gaboardi'), Perfil::AdminInstalacao, ['email' => 'outro@gaboardi.test']);
    }

    private function osm(array $resposta = [['lat' => '-29.1678', 'lon' => '-51.1794']]): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response($resposta)]);
    }

    public function test_cep_devolve_endereco_e_coordenadas_da_brasilapi(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response([
            'cep' => '95010000', 'state' => 'RS', 'city' => 'Caxias do Sul', 'neighborhood' => 'Centro', 'street' => 'Rua Sinimbu',
            'location' => ['type' => 'Point', 'coordinates' => ['longitude' => '-51.1794', 'latitude' => '-29.1678']],
        ])]);

        $this->actingAs($this->admin)->getJson(self::URL.'/pdvs/cep/95010-000')->assertOk()
            ->assertExactJson(['cep' => '95010-000', 'logradouro' => 'Rua Sinimbu', 'bairro' => 'Centro', 'cidade' => 'Caxias do Sul', 'uf' => 'RS', 'lat' => -29.1678, 'lng' => -51.1794]);
        // Segunda consulta do mesmo CEP vem do cache.
        $this->getJson(self::URL.'/pdvs/cep/95010000')->assertOk();
        Http::assertSentCount(1);
    }

    public function test_cep_cai_para_o_viacep_e_informa_quando_nao_existe(): void
    {
        Http::fake([
            'brasilapi.com.br/*' => Http::response(['name' => 'CepPromiseError'], 404),
            'viacep.com.br/ws/01001000/*' => Http::response(['cep' => '01001-000', 'logradouro' => 'Praça da Sé', 'bairro' => 'Sé', 'localidade' => 'São Paulo', 'uf' => 'SP']),
            'viacep.com.br/ws/99999999/*' => Http::response(['erro' => true]),
        ]);

        $this->actingAs($this->admin)->getJson(self::URL.'/pdvs/cep/01001000')->assertOk()
            ->assertJson(['logradouro' => 'Praça da Sé', 'cidade' => 'São Paulo', 'uf' => 'SP', 'lat' => null, 'lng' => null]);
        $this->getJson(self::URL.'/pdvs/cep/99999999')->assertNotFound()->assertJsonPath('message', fn ($m) => str_contains($m, 'CEP não encontrado'));
        $this->getJson(self::URL.'/pdvs/cep/123')->assertNotFound(); // fora do padrão de CEP
    }

    public function test_cep_exige_login_e_perfil_de_cadastro(): void
    {
        $this->get(self::URL.'/pdvs/cep/95010000')->assertRedirect();
        $gerente = $this->criarUsuario(Tenant::find('gaboardi'), Perfil::GerentePdv);
        $this->actingAs($gerente)->getJson(self::URL.'/pdvs/cep/95010000')->assertForbidden();
    }

    public function test_localizar_acha_o_ponto_pelo_endereco_sem_chave_do_google(): void
    {
        $this->osm();

        $this->actingAs($this->admin)->getJson(self::URL.'/pdvs/localizar?'.http_build_query([
            'logradouro' => 'Rua Sinimbu', 'numero' => '100', 'bairro' => 'Centro', 'cidade' => 'Caxias do Sul', 'uf' => 'RS', 'cep' => '95010-000',
        ]))->assertOk()->assertExactJson(['lat' => -29.1678, 'lng' => -51.1794]);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'nominatim.openstreetmap.org') && $r->hasHeader('User-Agent') && str_contains($r['q'], 'Rua Sinimbu, 100'));

        $this->getJson(self::URL.'/pdvs/localizar')->assertUnprocessable();
    }

    public function test_localizar_tenta_variacoes_do_endereco_ate_achar(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::sequence()->push([])->push([['lat' => '-29.17', 'lon' => '-51.18']])]);

        $this->actingAs($this->admin)->getJson(self::URL.'/pdvs/localizar?'.http_build_query([
            'logradouro' => 'Rua Sinimbu', 'numero' => '100', 'bairro' => 'Bairro Inexistente', 'cidade' => 'Caxias do Sul', 'uf' => 'RS',
        ]))->assertOk()->assertJson(['lat' => -29.17]);
        Http::assertSentCount(2);
    }

    public function test_endereco_nao_localizado_responde_404_com_orientacao(): void
    {
        $this->osm([]);

        $this->actingAs($this->admin)->getJson(self::URL.'/pdvs/localizar?cidade=Caxias&uf=RS&logradouro=Rua+X')
            ->assertNotFound()->assertJsonPath('message', fn ($m) => str_contains($m, 'Clique no mapa'));
    }

    public function test_cadastro_do_pdv_sem_coordenadas_busca_lat_lng_sozinho_pelo_openstreetmap(): void
    {
        $this->osm();

        $this->actingAs($this->admin)->post(self::URL.'/pdvs', [
            'cnpj' => '11.222.333/0001-81', 'nome' => 'Mercado Sinimbu', 'logradouro' => 'Rua Sinimbu', 'numero' => '100', 'bairro' => 'Centro',
            'cidade' => 'Caxias do Sul', 'uf' => 'rs', 'cep' => '95010000', 'canal' => 'supermercado', 'ativo' => '1',
        ])->assertSessionHasNoErrors();

        Tenant::find('gaboardi')->run(function () {
            $pdv = Pdv::sole();
            $this->assertEqualsWithDelta(-29.1678, (float) $pdv->lat, 0.00001);
            $this->assertEqualsWithDelta(-51.1794, (float) $pdv->lng, 0.00001);
            $this->assertSame('geocoding', $pdv->coordenadas_origem);
        });
    }

    public function test_formulario_mostra_o_cep_automatico(): void
    {
        $this->actingAs($this->admin)->get(self::URL.'/pdvs/create')->assertOk()
            ->assertSee('Ao digitar o CEP', false)->assertSee('pdvs\/localizar', false)->assertSee('pdv:ponto', false)
            ->assertSee('preenchidas automaticamente pelo endereço');
    }

    public function test_campos_de_data_usam_o_calendario_da_pagina_e_continuam_enviando_a_data_iso(): void
    {
        $this->actingAs($this->admin)->get(self::URL.'/contratos/create')->assertOk()
            ->assertSee('flatpickr', false)->assertSee('type="date"', false)->assertSee("altFormat: 'd/m/Y'", false)->assertSee("dateFormat: 'Y-m-d'", false);
        // Sem login (tela de entrada) o calendário não é carregado.
        auth()->logout();
        $this->get(self::URL.'/entrar')->assertOk()->assertDontSee('flatpickr', false);
    }

    public function test_variacoes_nunca_caem_para_a_cidade_inteira(): void
    {
        $pdv = new Pdv(['logradouro' => 'Rua Sinimbu', 'numero' => '100', 'bairro' => 'Centro', 'cidade' => 'Caxias do Sul', 'uf' => 'RS', 'cep' => '95010000']);
        $variacoes = $pdv->variacoesDeEndereco();

        $this->assertCount(3, $variacoes);
        $this->assertStringContainsString('Rua Sinimbu, 100', $variacoes[0]);
        $this->assertSame('Rua Sinimbu, 100, Caxias do Sul, RS, Brasil', $variacoes[1]);
        $this->assertSame('Rua Sinimbu, Caxias do Sul, RS, Brasil', $variacoes[2]);
        Cache::flush();
    }
}
