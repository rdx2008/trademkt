<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\Industria;
use App\Models\Sku;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SkuTest extends TestCase
{
    private const URL = 'http://gaboardi.test';

    private function admin(Tenant $tenant): User
    {
        return $tenant->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->first());
    }

    private function dadosSku(int $industriaId, array $extra = []): array
    {
        return array_merge([
            'industria_id' => $industriaId,
            'codigo' => 'ALG-25',
            'descricao' => 'Alumgrill papel alumínio 30 cm x 7,5 m',
            'categoria' => 'Descartáveis',
            'ativo' => '1',
            'embalagens' => [
                ['tipo' => 'unidade', 'quantidade' => '1', 'gtin' => '7891000315507', 'codigo' => ''],
                ['tipo' => 'reembalagem', 'quantidade' => '', 'gtin' => '', 'codigo' => ''], // em branco: ignorada
                ['tipo' => 'caixa_master', 'quantidade' => '25', 'gtin' => '17891000315504', 'codigo' => 'CX25'],
            ],
        ], $extra);
    }

    public function test_admin_cadastra_sku_com_embalagens_e_foto(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        $industria = $tenant->run(fn () => Industria::first());
        $this->actingAs($this->admin($tenant));

        $this->get(self::URL.'/skus/create')->assertOk()->assertSee('Caixa master');

        $this->post(self::URL.'/skus', $this->dadosSku($industria->id, [
            'foto' => UploadedFile::fake()->image('produto.jpg', 400, 400),
        ]))->assertSessionHasNoErrors()->assertRedirect(self::URL.'/skus')
            ->assertSessionHas('status', 'SKU cadastrado.');

        $sku = $tenant->run(function () use ($industria) {
            $sku = Sku::with('embalagens')->sole();
            $this->assertSame($industria->id, $sku->industria_id);
            $this->assertSame('Descartáveis', $sku->categoria);
            $this->assertCount(2, $sku->embalagens);

            $caixa = $sku->embalagens->firstWhere('tipo.value', 'caixa_master');
            $this->assertSame(25, $caixa->quantidade);
            $this->assertSame('17891000315504', $caixa->gtin);
            $this->assertTrue($caixa->gtin_valido);
            $this->assertSame('CX25', $caixa->codigo);

            $this->assertStringStartsWith("skus/{$industria->id}/", $sku->foto);
            $this->assertTrue(Storage::disk('local')->exists($sku->foto));

            return $sku;
        });

        // A foto fica na pasta do cliente.
        $this->assertFileExists(base_path("storage/clientes/gaboardi/app/{$sku->foto}"));

        $this->get(self::URL."/skus/{$sku->id}/foto")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get(self::URL.'/skus')->assertOk()->assertSee('ALG-25')->assertSee('17891000315504');
        $this->get(self::URL."/skus/{$sku->id}/edit")->assertOk()->assertSee('CX25')->assertSee('Remover foto');
    }

    public function test_codigo_e_unico_por_industria(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        [$gaboardi, $outra] = $tenant->run(fn () => [Industria::first(), Industria::create(['nome' => 'Outra'])]);
        $this->actingAs($this->admin($tenant));

        $this->post(self::URL.'/skus', $this->dadosSku($gaboardi->id))->assertSessionHasNoErrors();
        $this->post(self::URL.'/skus', $this->dadosSku($gaboardi->id, ['descricao' => 'Repetido']))
            ->assertSessionHasErrors(['codigo' => 'Já existe um SKU com este código nesta indústria.']);

        // Mesmo código em outra indústria é permitido.
        $this->post(self::URL.'/skus', $this->dadosSku($outra->id))->assertSessionHasNoErrors();

        // Editar o próprio SKU mantendo o código é permitido.
        $sku = $tenant->run(fn () => Sku::where('industria_id', $gaboardi->id)->sole());
        $this->put(self::URL."/skus/{$sku->id}", $this->dadosSku($gaboardi->id, ['descricao' => 'Nova descrição']))
            ->assertSessionHasNoErrors();

        $tenant->run(function () {
            $this->assertSame(2, Sku::count());
            $this->assertSame(1, Sku::where('descricao', 'Nova descrição')->count());
        });
    }

    public function test_gtin_com_digito_errado_e_marcado_e_nao_bloqueado(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        $industria = $tenant->run(fn () => Industria::first());
        $this->actingAs($this->admin($tenant));

        $dados = $this->dadosSku($industria->id);
        $dados['embalagens'][0]['gtin'] = '789 1000 315508'; // dígito errado, com espaços

        $this->post(self::URL.'/skus', $dados)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'GTIN com dígito verificador errado (7891000315508)'));

        $tenant->run(function () {
            $unidade = Sku::sole()->embalagens()->where('tipo', 'unidade')->sole();
            $this->assertSame('7891000315508', $unidade->gtin);
            $this->assertFalse($unidade->gtin_valido);
            $this->assertTrue($unidade->gtinComAlerta());
        });

        $this->get(self::URL.'/skus')->assertSee('Dígito verificador errado');
        $this->get(self::URL.'/skus?alerta=gtin')->assertSee('ALG-25');

        // Corrigido o GTIN, a marca some.
        $sku = $tenant->run(fn () => Sku::with('embalagens')->sole());
        $dados = $this->dadosSku($industria->id);
        foreach ($sku->embalagens as $i => $embalagem) {
            $dados['embalagens'][$i === 0 ? 0 : 2]['id'] = $embalagem->id;
        }
        $this->put(self::URL."/skus/{$sku->id}", $dados)->assertSessionHasNoErrors()->assertSessionHas('status', 'SKU atualizado.');
        $this->get(self::URL.'/skus?alerta=gtin')->assertDontSee('ALG-25');
    }

    public function test_gtin_fora_do_formato_e_recusado(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        $industria = $tenant->run(fn () => Industria::first());
        $this->actingAs($this->admin($tenant));

        foreach (['1234567', '78910003155A7', '123456789012345'] as $gtin) {
            $dados = $this->dadosSku($industria->id);
            $dados['embalagens'][0]['gtin'] = $gtin;

            $this->post(self::URL.'/skus', $dados)
                ->assertSessionHasErrors(['embalagens.0.gtin' => 'O GTIN deve ter 8, 12, 13 ou 14 dígitos.']);
        }

        $dados = $this->dadosSku($industria->id);
        $dados['embalagens'][0]['quantidade'] = '0';
        $this->post(self::URL.'/skus', $dados)->assertSessionHasErrors('embalagens.0.quantidade');

        $tenant->run(fn () => $this->assertSame(0, Sku::count()));
    }

    public function test_edicao_sincroniza_embalagens_e_troca_a_foto(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        $industria = $tenant->run(fn () => Industria::first());
        $this->actingAs($this->admin($tenant));

        $this->post(self::URL.'/skus', $this->dadosSku($industria->id, ['foto' => UploadedFile::fake()->image('a.png')]));
        $sku = $tenant->run(fn () => Sku::with('embalagens')->sole());
        $fotoAntiga = $sku->foto;
        $unidade = $sku->embalagens->firstWhere('tipo.value', 'unidade');

        // Mantém a unidade (com GTIN novo), tira a caixa de 25 e inclui uma caixa de 12.
        $this->put(self::URL."/skus/{$sku->id}", $this->dadosSku($industria->id, [
            'foto' => UploadedFile::fake()->image('b.png'),
            'embalagens' => [
                ['id' => $unidade->id, 'tipo' => 'unidade', 'quantidade' => '1', 'gtin' => '7896090700103'],
                ['tipo' => 'caixa_master', 'quantidade' => '12', 'gtin' => '', 'codigo' => 'CX12'],
            ],
        ]))->assertSessionHasNoErrors();

        $tenant->run(function () use ($sku, $unidade, $fotoAntiga) {
            $sku->refresh()->load('embalagens');
            $this->assertCount(2, $sku->embalagens);
            $this->assertSame('7896090700103', $sku->embalagens->find($unidade->id)->gtin);
            $caixa = $sku->embalagens->firstWhere('tipo.value', 'caixa_master');
            $this->assertSame(12, $caixa->quantidade);
            $this->assertNull($caixa->gtin);
            $this->assertNull($caixa->gtin_valido);

            $this->assertNotSame($fotoAntiga, $sku->foto);
            $this->assertFalse(Storage::disk('local')->exists($fotoAntiga), 'A foto antiga é apagada.');
            $this->assertTrue(Storage::disk('local')->exists($sku->foto));
        });

        // Embalagem de outro SKU não pode ser sequestrada pelo id.
        $outro = $tenant->run(fn () => Sku::create(['industria_id' => $industria->id, 'codigo' => 'X1', 'descricao' => 'Outro']));
        $this->put(self::URL."/skus/{$outro->id}", $this->dadosSku($industria->id, [
            'codigo' => 'X1',
            'embalagens' => [['id' => $unidade->id, 'tipo' => 'unidade', 'quantidade' => '1']],
        ]))->assertSessionHasErrors('embalagens.0.id');

        // Remover a foto.
        $this->put(self::URL."/skus/{$sku->id}", $this->dadosSku($industria->id, ['remover_foto' => '1']))->assertSessionHasNoErrors();
        $tenant->run(fn () => $this->assertNull($sku->fresh()->foto));
    }

    public function test_gerente_de_trade_so_ve_e_cadastra_a_propria_marca(): void
    {
        $tenant = $this->criarCliente('xyz', 'agencia', 'xyz.test');
        [$marcaA, $marcaB] = $tenant->run(fn () => [Industria::create(['nome' => 'Marca A']), Industria::create(['nome' => 'Marca B'])]);
        $skuB = $tenant->run(fn () => Sku::create(['industria_id' => $marcaB->id, 'codigo' => 'B1', 'descricao' => 'Produto da marca B']));

        $gerenteA = $this->criarUsuario($tenant, Perfil::GerenteTrade, ['industria_id' => $marcaA->id]);
        $this->actingAs($gerenteA);

        // Tenta cadastrar na marca B: o sistema força a própria marca.
        $this->post('http://xyz.test/skus', $this->dadosSku($marcaB->id, ['codigo' => 'A1', 'descricao' => 'Produto da marca A']))
            ->assertSessionHasNoErrors();
        $tenant->run(fn () => $this->assertSame($marcaA->id, Sku::where('codigo', 'A1')->sole()->industria_id));

        $this->get('http://xyz.test/skus')->assertOk()->assertSee('Produto da marca A')->assertDontSee('Produto da marca B');
        $this->get("http://xyz.test/skus/{$skuB->id}/edit")->assertForbidden();
        $this->put("http://xyz.test/skus/{$skuB->id}", $this->dadosSku($marcaB->id, ['codigo' => 'B1']))->assertForbidden();
        $this->get("http://xyz.test/skus/{$skuB->id}/foto")->assertNotFound();

        // Representante da marca A só consulta.
        $representante = $this->criarUsuario($tenant, Perfil::Representante, ['industria_id' => $marcaA->id]);
        $this->actingAs($representante)->get('http://xyz.test/skus')->assertOk()->assertSee('Produto da marca A')->assertDontSee('Novo SKU');
        $this->actingAs($representante)->get('http://xyz.test/skus/create')->assertForbidden();
    }

    public function test_agencia_consulta_o_catalogo_mas_nao_edita(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        [$industria, $agencia] = $tenant->run(fn () => [Industria::first(), Agencia::create(['nome' => 'Agência'])]);
        $sku = $tenant->run(fn () => Sku::create(['industria_id' => $industria->id, 'codigo' => 'P1', 'descricao' => 'Esponja multiuso']));

        foreach ([Perfil::AdminAgencia, Perfil::Supervisor] as $perfil) {
            $usuario = $this->criarUsuario($tenant, $perfil, ['agencia_id' => $agencia->id]);
            $this->actingAs($usuario)->get(self::URL.'/skus')->assertOk()->assertSee('Esponja multiuso')->assertDontSee('Novo SKU');
            $this->actingAs($usuario)->post(self::URL.'/skus', $this->dadosSku($industria->id))->assertForbidden();
            $this->actingAs($usuario)->get(self::URL."/skus/{$sku->id}/edit")->assertForbidden();
        }

        $promotor = $this->criarUsuario($tenant, Perfil::Promotor, ['agencia_id' => $agencia->id, 'tipo_vinculo' => 'clt']);
        $this->actingAs($promotor)->get(self::URL.'/skus')->assertForbidden();
    }

    public function test_busca_por_codigo_descricao_e_gtin(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        $industria = $tenant->run(fn () => Industria::first());
        $this->actingAs($this->admin($tenant));

        $this->post(self::URL.'/skus', $this->dadosSku($industria->id));
        $this->post(self::URL.'/skus', $this->dadosSku($industria->id, [
            'codigo' => 'ESP-18', 'descricao' => 'Espetinho de bambu 18 cm', 'categoria' => 'Churrasco',
            'embalagens' => [['tipo' => 'unidade', 'quantidade' => '1', 'gtin' => '7896090700103']],
        ]));

        $this->get(self::URL.'/skus?busca=espetinho')->assertSee('ESP-18')->assertDontSee('ALG-25');
        $this->get(self::URL.'/skus?busca=alg-25')->assertSee('ALG-25')->assertDontSee('ESP-18');
        $this->get(self::URL.'/skus?busca=78960907')->assertSee('ESP-18')->assertDontSee('ALG-25');
        $this->get(self::URL.'/skus?busca=CX25')->assertSee('ALG-25')->assertDontSee('ESP-18');
        $this->get(self::URL.'/skus?categoria=Churrasco')->assertSee('ESP-18')->assertDontSee('ALG-25');
    }
}
