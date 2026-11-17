<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\Industria;
use App\Models\Sku;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vinculo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Fase 9, item 9.7: a agência vinculada importa o catálogo da indústria, quando o vínculo permite. */
class CatalogoPelaAgenciaTest extends TestCase
{
    private const SUL = 'http://sul.test';

    private Tenant $industria;

    private Tenant $agencia;

    private Agencia $local;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->industria = $this->criarCliente('gaboardi');
        $this->agencia = $this->criarCliente('sul', 'agencia');
        $this->local = $this->vincular($this->industria, $this->agencia);
        tenancy()->end();
        $this->admin = $this->agencia->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
    }

    private function csv(): UploadedFile
    {
        $texto = "Código;Descrição;Categoria;GTIN unidade\r\nDET-01;Detergente 500 ml;Limpeza;\r\nESP-02;Esponja dupla;Limpeza;\r\n";

        return UploadedFile::fake()->createWithContent('produtos.csv', mb_convert_encoding($texto, 'Windows-1252', 'UTF-8'));
    }

    private function entrarNaOperacao(User $usuario): void
    {
        $this->actingAs($usuario)->post(self::SUL.'/operacao/gaboardi')->assertRedirect();
    }

    public function test_agencia_ve_o_catalogo_da_industria_mesmo_sem_contrato(): void
    {
        $this->industria->run(function () {
            $marca = Industria::first();
            Sku::create(['industria_id' => $marca->id, 'codigo' => 'DET-01', 'descricao' => 'Detergente 500 ml', 'ativo' => true]);
        });

        $this->entrarNaOperacao($this->admin);
        $this->get(self::SUL.'/skus')->assertOk()->assertSee('Detergente 500 ml');
    }

    public function test_agencia_importa_o_catalogo_e_fica_a_trilha(): void
    {
        $this->entrarNaOperacao($this->admin);
        $this->get(self::SUL.'/painel')->assertOk()->assertSee('Importar produtos');
        $this->get(self::SUL.'/skus/importar')->assertOk();

        $marca = $this->industria->run(fn () => Industria::first());
        $resposta = $this->post(self::SUL.'/skus/importar', ['industria_id' => $marca->id, 'arquivo' => $this->csv()])->assertSessionHasNoErrors();
        $id = basename((string) $resposta->headers->get('Location'));
        $this->get(self::SUL."/skus/importar/{$id}")->assertOk()->assertSee('Detergente 500 ml');
        $this->post(self::SUL."/skus/importar/{$id}", ['itens' => [2, 3]])->assertSessionHasNoErrors()->assertRedirect();

        $this->industria->run(function () {
            $this->assertSame(['DET-01', 'ESP-02'], Sku::orderBy('codigo')->pluck('codigo')->all());
            $trilha = DB::table('importacoes_catalogo')->sole();
            $this->assertSame($this->local->id, (int) $trilha->agencia_id);
            $this->assertSame($this->admin->nome, $trilha->usuario_nome);
            $this->assertSame(2, (int) $trilha->criados);
        });
        $this->assertSame(0, $this->agencia->run(fn () => Sku::count()), 'nada vai para o banco da agência');
    }

    public function test_industria_desliga_a_permissao_e_a_agencia_perde_a_importacao(): void
    {
        Vinculo::sole()->update(['permissoes' => ['agencia_mantem_catalogo' => false]]);
        $this->entrarNaOperacao($this->admin);
        $this->get(self::SUL.'/skus/importar')->assertForbidden();
        $this->get(self::SUL.'/painel')->assertOk()->assertDontSee('Importar produtos');
    }

    public function test_supervisor_da_agencia_nao_importa_e_fora_da_operacao_nao_ha_importacao(): void
    {
        $supervisor = $this->criarUsuario($this->agencia, Perfil::Supervisor, ['agencia_id' => 1]);
        $this->entrarNaOperacao($supervisor);
        $this->get(self::SUL.'/skus/importar')->assertForbidden();

        // Revisão de outra pessoa (mesmo id em outro banco) não é aberta.
        $this->app['auth']->forgetGuards();
        $adminIndustria = $this->industria->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
        $marca = $this->industria->run(fn () => Industria::first());
        $resposta = $this->actingAs($adminIndustria)->post('http://gaboardi.test/skus/importar', ['industria_id' => $marca->id, 'arquivo' => $this->csv()]);
        $id = basename((string) $resposta->headers->get('Location'));
        $this->app['auth']->forgetGuards();
        $this->entrarNaOperacao($this->admin);
        $this->get(self::SUL."/skus/importar/{$id}")->assertNotFound();
    }
}
