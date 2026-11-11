<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\ContratoVisita;
use App\Models\Industria;
use App\Models\Pdv;
use App\Models\Rede;
use App\Models\Regiao;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PdvTest extends TestCase
{
    private const URL = 'http://gaboardi.test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['trade.geocoding.google_key' => 'chave-teste']);
    }

    private function admin(Tenant $tenant): User
    {
        return $tenant->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->first());
    }

    private function dadosPdv(array $extra = []): array
    {
        return array_merge([
            'cnpj' => '11.222.333/0001-81',
            'nome' => 'Supermercado Bom Preço',
            'logradouro' => 'Rua das Flores',
            'numero' => '100',
            'bairro' => 'Centro',
            'cidade' => 'Caxias do Sul',
            'uf' => 'rs',
            'cep' => '95010-000',
            'canal' => 'supermercado',
            'ativo' => '1',
        ], $extra);
    }

    private function fakeGeocoding(float $lat = -29.1678, float $lng = -51.1794): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'results' => [['geometry' => ['location' => ['lat' => $lat, 'lng' => $lng]]]],
            ]),
        ]);
    }

    private function criarPdv(Tenant $tenant, int $base, array $extra = []): Pdv
    {
        return $tenant->run(fn () => Pdv::create(array_merge([
            'cnpj' => self::cnpj($base),
            'nome' => "Loja {$base}",
            'cidade' => 'Caxias do Sul',
            'uf' => 'RS',
        ], $extra)));
    }

    public function test_admin_cadastra_pdv_com_geocoding(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        $this->fakeGeocoding();

        $this->actingAs($this->admin($tenant))
            ->post(self::URL.'/pdvs', $this->dadosPdv())
            ->assertSessionHasNoErrors()
            ->assertRedirect(self::URL.'/pdvs');

        Http::assertSent(fn ($r) => $r['address'] === 'Rua das Flores, 100, Centro, Caxias do Sul - RS, 95010-000, Brasil'
            && $r['key'] === 'chave-teste');

        $tenant->run(function () {
            $pdv = Pdv::sole();
            $this->assertSame('11222333000181', $pdv->cnpj);
            $this->assertSame('RS', $pdv->uf);
            $this->assertSame('95010000', $pdv->cep);
            $this->assertSame(200, $pdv->raio_m);
            $this->assertEqualsWithDelta(-29.1678, $pdv->lat, 1e-6);
            $this->assertEqualsWithDelta(-51.1794, $pdv->lng, 1e-6);
            $this->assertSame('geocoding', $pdv->coordenadas_origem);
        });
    }

    public function test_raio_fora_de_150_a_300_e_recusado(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        $this->fakeGeocoding();
        $this->actingAs($this->admin($tenant));

        foreach ([149, 301, 1000] as $raio) {
            $this->post(self::URL.'/pdvs', $this->dadosPdv(['raio_m' => $raio]))
                ->assertSessionHasErrors(['raio_m' => 'O raio de check-in deve ficar entre 150 e 300 metros.']);
        }

        $this->post(self::URL.'/pdvs', $this->dadosPdv(['raio_m' => 150]))->assertSessionHasNoErrors();
        $this->post(self::URL.'/pdvs', $this->dadosPdv(['cnpj' => self::cnpj(2), 'raio_m' => 300]))->assertSessionHasNoErrors();

        $tenant->run(fn () => $this->assertSame([150, 300], Pdv::orderBy('id')->pluck('raio_m')->all()));
    }

    public function test_cnpj_invalido_ou_repetido_e_recusado(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        $this->fakeGeocoding();
        $this->actingAs($this->admin($tenant));

        $this->post(self::URL.'/pdvs', $this->dadosPdv(['cnpj' => '11.222.333/0001-82']))
            ->assertSessionHasErrors('cnpj');
        $this->post(self::URL.'/pdvs', $this->dadosPdv(['cnpj' => '']))
            ->assertSessionHasErrors('cnpj');

        $this->post(self::URL.'/pdvs', $this->dadosPdv())->assertSessionHasNoErrors();
        // Mesmo CNPJ, sem máscara: continua repetido.
        $this->post(self::URL.'/pdvs', $this->dadosPdv(['cnpj' => '11222333000181', 'nome' => 'Outro']))
            ->assertSessionHasErrors(['cnpj' => 'Já existe um PDV com este CNPJ.']);

        // Editar o próprio PDV mantendo o CNPJ é permitido.
        $pdv = $tenant->run(fn () => Pdv::sole());
        $this->put(self::URL."/pdvs/{$pdv->id}", $this->dadosPdv(['nome' => 'Novo nome', 'lat' => $pdv->lat, 'lng' => $pdv->lng]))
            ->assertSessionHasNoErrors();

        $tenant->run(fn () => $this->assertSame(1, Pdv::count()));
    }

    public function test_ajuste_manual_das_coordenadas_prevalece(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        $this->fakeGeocoding();
        $this->actingAs($this->admin($tenant));

        // Pino posicionado no mapa já no cadastro: não consulta o geocoding.
        $this->post(self::URL.'/pdvs', $this->dadosPdv(['lat' => '-29.1', 'lng' => '-51.2']))->assertSessionHasNoErrors();
        Http::assertNothingSent();

        $pdv = $tenant->run(fn () => Pdv::sole());
        $this->assertSame('manual', $pdv->coordenadas_origem);

        $this->get(self::URL."/pdvs/{$pdv->id}/edit")
            ->assertOk()
            ->assertSee('11.222.333/0001-81')
            ->assertSee('value="-29.1"', false);

        // Muda o endereço mantendo o pino: o ajuste manual continua valendo.
        $this->put(self::URL."/pdvs/{$pdv->id}", $this->dadosPdv(['numero' => '200', 'lat' => '-29.1', 'lng' => '-51.2']))
            ->assertSessionHasNoErrors();
        Http::assertNothingSent();

        $tenant->run(function () {
            $pdv = Pdv::sole();
            $this->assertSame('200', $pdv->numero);
            $this->assertEqualsWithDelta(-29.1, $pdv->lat, 1e-6);
            $this->assertSame('manual', $pdv->coordenadas_origem);
        });
    }

    public function test_arrastar_o_pino_de_um_pdv_geocodificado_vira_ajuste_manual(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        $this->fakeGeocoding(-29.0, -51.0);
        $this->actingAs($this->admin($tenant));

        $this->post(self::URL.'/pdvs', $this->dadosPdv())->assertSessionHasNoErrors();
        $pdv = $tenant->run(fn () => Pdv::sole());

        $this->put(self::URL."/pdvs/{$pdv->id}", $this->dadosPdv(['lat' => '-29.0005', 'lng' => '-51.0003']))
            ->assertSessionHasNoErrors();

        Http::assertSentCount(1);
        $tenant->run(function () {
            $pdv = Pdv::sole();
            $this->assertEqualsWithDelta(-29.0005, $pdv->lat, 1e-6);
            $this->assertSame('manual', $pdv->coordenadas_origem);
        });
    }

    public function test_endereco_alterado_recalcula_coordenadas_do_geocoding(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        Http::fake([
            'maps.googleapis.com/*' => Http::sequence()
                ->push(['status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => -29.0, 'lng' => -51.0]]]]])
                ->push(['status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => -29.5, 'lng' => -51.5]]]]]),
        ]);
        $this->actingAs($this->admin($tenant));

        $this->post(self::URL.'/pdvs', $this->dadosPdv())->assertSessionHasNoErrors();
        $pdv = $tenant->run(fn () => Pdv::sole());

        // Endereço novo, mesmas coordenadas (o formulário reenvia as antigas).
        $this->put(self::URL."/pdvs/{$pdv->id}", $this->dadosPdv(['logradouro' => 'Av. Julio de Castilhos', 'lat' => $pdv->lat, 'lng' => $pdv->lng]))
            ->assertSessionHasNoErrors();

        Http::assertSentCount(2);
        $tenant->run(fn () => $this->assertEqualsWithDelta(-29.5, Pdv::sole()->lat, 1e-6));
    }

    public function test_geocoding_usa_cache_para_o_mesmo_endereco(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        $this->fakeGeocoding();
        $this->actingAs($this->admin($tenant));

        $this->post(self::URL.'/pdvs', $this->dadosPdv())->assertSessionHasNoErrors();
        $this->post(self::URL.'/pdvs', $this->dadosPdv(['cnpj' => self::cnpj(7), 'nome' => 'Outra loja no mesmo prédio']))
            ->assertSessionHasNoErrors();

        Http::assertSentCount(1);
        $tenant->run(fn () => $this->assertSame(2, Pdv::whereNotNull('lat')->count()));
    }

    public function test_sem_chave_de_geocoding_salva_sem_coordenadas_e_avisa(): void
    {
        config(['trade.geocoding.google_key' => null]);
        $tenant = $this->criarCliente('gaboardi');
        Http::fake();
        $this->actingAs($this->admin($tenant));

        $this->post(self::URL.'/pdvs', $this->dadosPdv())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'ajuste o pino no mapa'));

        Http::assertNothingSent();
        $tenant->run(fn () => $this->assertFalse(Pdv::sole()->temCoordenadas()));

        $this->get(self::URL.'/pdvs')->assertSee('Sem coordenadas');
    }

    public function test_endereco_nao_encontrado_nao_quebra_o_cadastro(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []])]);
        $this->actingAs($this->admin($tenant));

        $this->post(self::URL.'/pdvs', $this->dadosPdv())->assertSessionHasNoErrors();

        $tenant->run(fn () => $this->assertNull(Pdv::sole()->lat));
    }

    public function test_busca_e_filtros(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        [$rede, $regiao] = $tenant->run(fn () => [Rede::create(['nome' => 'Rede Sul']), Regiao::create(['nome' => 'Serra'])]);

        $this->criarPdv($tenant, 1, ['nome' => 'Mercado Alfa', 'rede_id' => $rede->id, 'canal' => 'supermercado']);
        $this->criarPdv($tenant, 2, ['nome' => 'Atacado Beta', 'regiao_id' => $regiao->id, 'canal' => 'atacarejo']);
        $this->criarPdv($tenant, 3, ['nome' => 'Farmácia Gama', 'cidade' => 'Curitiba', 'uf' => 'PR', 'ativo' => false]);

        $this->actingAs($this->admin($tenant));

        $this->get(self::URL.'/pdvs')->assertOk()->assertSee(['Mercado Alfa', 'Atacado Beta', 'Farmácia Gama']);
        $this->get(self::URL.'/pdvs?busca=beta')->assertSee('Atacado Beta')->assertDontSee('Mercado Alfa');
        $this->get(self::URL.'/pdvs?busca='.substr(self::cnpj(3), 0, 8))->assertSee('Farmácia Gama')->assertDontSee('Atacado Beta');
        $this->get(self::URL.'/pdvs?busca=curitiba')->assertSee('Farmácia Gama')->assertDontSee('Mercado Alfa');
        $this->get(self::URL.'/pdvs?rede_id='.$rede->id)->assertSee('Mercado Alfa')->assertDontSee('Atacado Beta');
        $this->get(self::URL.'/pdvs?regiao_id='.$regiao->id)->assertSee('Atacado Beta')->assertDontSee('Mercado Alfa');
        $this->get(self::URL.'/pdvs?canal=atacarejo')->assertSee('Atacado Beta')->assertDontSee('Mercado Alfa');
        $this->get(self::URL.'/pdvs?uf=pr')->assertSee('Farmácia Gama')->assertDontSee('Atacado Beta');
        $this->get(self::URL.'/pdvs?situacao=inativos')->assertSee('Farmácia Gama')->assertDontSee('Mercado Alfa');
    }

    public function test_industria_na_instalacao_de_agencia_so_ve_pdvs_com_contrato_da_sua_industria(): void
    {
        $tenant = $this->criarCliente('xyz', 'agencia', 'xyz.test');
        [$agencia, $marcaA, $marcaB] = $tenant->run(fn () => [
            Agencia::first(),
            Industria::create(['nome' => 'Marca A']),
            Industria::create(['nome' => 'Marca B']),
        ]);

        $comContratoA = $this->criarPdv($tenant, 1, ['nome' => 'Loja Contrato A']);
        $comContratoB = $this->criarPdv($tenant, 2, ['nome' => 'Loja Contrato B']);
        $contratoInativo = $this->criarPdv($tenant, 3, ['nome' => 'Loja Contrato Encerrado']);
        $this->criarPdv($tenant, 4, ['nome' => 'Loja Sem Contrato']);

        $tenant->run(function () use ($agencia, $marcaA, $marcaB, $comContratoA, $comContratoB, $contratoInativo) {
            $base = ['agencia_id' => $agencia->id, 'frequencia_semana' => 1, 'dias' => [1], 'inicio' => '2026-01-01'];
            ContratoVisita::create($base + ['pdv_id' => $comContratoA->id, 'industria_id' => $marcaA->id]);
            ContratoVisita::create($base + ['pdv_id' => $comContratoB->id, 'industria_id' => $marcaB->id]);
            ContratoVisita::create($base + ['pdv_id' => $contratoInativo->id, 'industria_id' => $marcaA->id, 'ativo' => false]);
        });

        foreach ([Perfil::GerenteTrade, Perfil::Representante] as $perfil) {
            $usuario = $this->criarUsuario($tenant, $perfil, ['industria_id' => $marcaA->id]);

            $this->actingAs($usuario)
                ->get('http://xyz.test/pdvs')
                ->assertOk()
                ->assertSee('Loja Contrato A')
                ->assertDontSee('Loja Contrato B')
                ->assertDontSee('Loja Contrato Encerrado')
                ->assertDontSee('Loja Sem Contrato')
                ->assertDontSee('Novo PDV');
        }

        // A agência vê todos.
        $supervisor = $this->criarUsuario($tenant, Perfil::Supervisor, ['agencia_id' => $agencia->id]);
        $this->actingAs($supervisor)
            ->get('http://xyz.test/pdvs')
            ->assertSee(['Loja Contrato A', 'Loja Contrato B', 'Loja Sem Contrato']);
    }

    public function test_industria_na_propria_instalacao_ve_todos_os_pdvs(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        $industria = $tenant->run(fn () => Industria::first());
        $this->criarPdv($tenant, 1, ['nome' => 'Loja Um']);
        $this->criarPdv($tenant, 2, ['nome' => 'Loja Dois']);

        $gerente = $this->criarUsuario($tenant, Perfil::GerenteTrade, ['industria_id' => $industria->id]);

        $this->actingAs($gerente)->get(self::URL.'/pdvs')->assertOk()->assertSee(['Loja Um', 'Loja Dois']);
    }

    public function test_permissoes_de_cadastro(): void
    {
        $tenant = $this->criarCliente('xyz', 'agencia', 'xyz.test');
        $agencia = $tenant->run(fn () => Agencia::first());
        $this->fakeGeocoding();

        $promotor = $this->criarUsuario($tenant, Perfil::Promotor, ['agencia_id' => $agencia->id, 'tipo_vinculo' => 'clt']);
        $this->actingAs($promotor)->get('http://xyz.test/pdvs')->assertForbidden();

        $supervisor = $this->criarUsuario($tenant, Perfil::Supervisor, ['agencia_id' => $agencia->id]);
        $this->actingAs($supervisor)->get('http://xyz.test/pdvs')->assertOk();
        $this->actingAs($supervisor)->get('http://xyz.test/pdvs/create')->assertForbidden();
        $this->actingAs($supervisor)->post('http://xyz.test/pdvs', $this->dadosPdv())->assertForbidden();
        $this->actingAs($supervisor)->get('http://xyz.test/pdvs/importar')->assertForbidden();
        $this->actingAs($supervisor)->get('http://xyz.test/regioes')->assertForbidden();

        $gerente = $this->criarUsuario($tenant, Perfil::GerenteTrade, ['industria_id' => $tenant->run(fn () => Industria::create(['nome' => 'M'])->id)]);
        $this->actingAs($gerente)->post('http://xyz.test/pdvs', $this->dadosPdv())->assertForbidden();

        $adminAgencia = $this->criarUsuario($tenant, Perfil::AdminAgencia, ['agencia_id' => $agencia->id]);
        $this->actingAs($adminAgencia)->get('http://xyz.test/pdvs/create')->assertOk()->assertSee('leaflet');
        $this->actingAs($adminAgencia)->post('http://xyz.test/pdvs', $this->dadosPdv())->assertSessionHasNoErrors();

        $tenant->run(fn () => $this->assertSame(1, Pdv::count()));
    }

    public function test_gerente_do_supermercado_vinculado_as_lojas_ve_so_elas(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        $loja1 = $this->criarPdv($tenant, 1, ['nome' => 'Loja Um']);
        $loja2 = $this->criarPdv($tenant, 2, ['nome' => 'Loja Dois']);
        $this->criarPdv($tenant, 3, ['nome' => 'Loja Três']);

        $this->actingAs($this->admin($tenant))
            ->post(self::URL.'/usuarios', [
                'nome' => 'Gerente da loja',
                'email' => 'gerente@loja.test',
                'perfil' => 'gerente_pdv',
                'pdvs' => [$loja1->id, $loja2->id],
                'password' => 'senha1234',
                'password_confirmation' => 'senha1234',
                'ativo' => '1',
            ])->assertSessionHasNoErrors();

        $gerente = $tenant->run(fn () => User::where('email', 'gerente@loja.test')->first());
        $tenant->run(fn () => $this->assertEqualsCanonicalizing([$loja1->id, $loja2->id], $gerente->pdvsGerenciados()->pluck('pdvs.id')->all()));

        $this->actingAs($gerente)
            ->get(self::URL.'/pdvs')
            ->assertOk()
            ->assertSee(['Loja Um', 'Loja Dois'])
            ->assertDontSee('Loja Três');

        // Troca de perfil limpa o vínculo.
        $this->actingAs($this->admin($tenant))
            ->put(self::URL."/usuarios/{$gerente->id}", [
                'nome' => 'Gerente da loja',
                'email' => 'gerente@loja.test',
                'perfil' => 'representante',
                'industria_id' => $tenant->run(fn () => Industria::first()->id),
                'pdvs' => [$loja1->id],
                'ativo' => '1',
            ])->assertSessionHasNoErrors();

        $tenant->run(function () use ($gerente, $loja1) {
            $this->assertSame(0, $gerente->pdvsGerenciados()->count());
            $this->assertSame([$loja1->id], $gerente->carteiraPdvs()->pluck('pdvs.id')->all());
        });
    }

    public function test_carteira_do_representante_so_aceita_pdvs_visiveis_para_quem_cadastra(): void
    {
        $tenant = $this->criarCliente('xyz', 'agencia', 'xyz.test');
        [$agencia, $marca] = $tenant->run(fn () => [Agencia::first(), Industria::create(['nome' => 'Marca A'])]);

        $comContrato = $this->criarPdv($tenant, 1, ['nome' => 'Loja Contrato']);
        $semContrato = $this->criarPdv($tenant, 2, ['nome' => 'Loja Sem Contrato']);
        $tenant->run(fn () => ContratoVisita::create([
            'pdv_id' => $comContrato->id, 'industria_id' => $marca->id, 'agencia_id' => $agencia->id,
            'frequencia_semana' => 1, 'dias' => [2], 'inicio' => '2026-01-01',
        ]));

        $gerenteTrade = $this->criarUsuario($tenant, Perfil::GerenteTrade, ['industria_id' => $marca->id]);
        $this->actingAs($gerenteTrade);

        $this->get('http://xyz.test/usuarios/create')->assertOk()->assertSee('Loja Contrato')->assertDontSee('Loja Sem Contrato');

        $dados = [
            'nome' => 'Rep',
            'email' => 'rep@xyz.test',
            'perfil' => 'representante',
            'password' => 'senha1234',
            'password_confirmation' => 'senha1234',
            'ativo' => '1',
        ];

        $this->post('http://xyz.test/usuarios', $dados + ['pdvs' => [$comContrato->id, $semContrato->id]])
            ->assertSessionHasErrors('pdvs');
        $tenant->run(fn () => $this->assertNull(User::where('email', 'rep@xyz.test')->first()));

        $this->post('http://xyz.test/usuarios', $dados + ['pdvs' => [$comContrato->id]])->assertSessionHasNoErrors();

        $tenant->run(function () use ($comContrato, $marca) {
            $rep = User::where('email', 'rep@xyz.test')->first();
            $this->assertSame($marca->id, $rep->industria_id);
            $this->assertSame([$comContrato->id], $rep->carteiraPdvs()->pluck('pdvs.id')->all());
        });
    }

    public function test_cadastro_de_regioes_e_redes(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        $this->actingAs($this->admin($tenant));

        $this->get(self::URL.'/regioes/novo')->assertOk()->assertSee('Nova região');
        $this->get(self::URL.'/pdvs/importar')->assertOk()->assertSee('Obrigatórias:');

        foreach (['regioes' => 'Serra Gaúcha', 'redes' => 'Rede Econômica'] as $rota => $nome) {
            $this->post(self::URL."/{$rota}", ['nome' => $nome, 'ativo' => '1'])->assertRedirect(self::URL."/{$rota}");
            $this->post(self::URL."/{$rota}", ['nome' => $nome, 'ativo' => '1'])->assertSessionHasErrors('nome');
            $this->get(self::URL."/{$rota}")->assertOk()->assertSee($nome);
        }

        $regiao = $tenant->run(fn () => Regiao::sole());
        $this->put(self::URL."/regioes/{$regiao->id}", ['nome' => 'Serra', 'ativo' => '0'])->assertRedirect(self::URL.'/regioes');
        $this->get(self::URL."/regioes/{$regiao->id}/editar")->assertOk()->assertSee('Serra');

        $tenant->run(function () {
            $this->assertSame('Serra', Regiao::sole()->nome);
            $this->assertFalse(Regiao::sole()->ativo);
            $this->assertSame('Rede Econômica', Rede::sole()->nome);
        });
    }
}
