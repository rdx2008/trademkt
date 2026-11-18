<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Jobs\GerarExportacao;
use App\Models\Agencia;
use App\Models\ColetaResposta;
use App\Models\Exportacao;
use App\Models\FotoAvaliacao;
use App\Models\Industria;
use App\Models\ModeloColeta;
use App\Models\Pdv;
use App\Models\Roteiro;
use App\Models\Sku;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visita;
use App\Models\VisitaFoto;
use App\Services\Coletas\Formulario;
use App\Services\Coletas\ModelosDePartida;
use App\Services\Coletas\ResultadosColeta;
use App\Services\Operacao\BookDeFotos;
use App\Services\Operacao\ExcelIndicadores;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/** Fase 10 (10.8): fila de downloads, com arquivo privado do dono e validade de 7 dias. */
class FilaDeDownloadsTest extends TestCase
{
    private const URL = 'http://xyz.test';

    private Tenant $tenant;

    private User $admin;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-31 12:00:00');
        $this->tenant = $this->criarCliente('xyz', 'agencia');
        $agencia = $this->tenant->run(fn () => Agencia::first());
        $this->admin = $this->tenant->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
        $this->supervisor = $this->criarUsuario($this->tenant, Perfil::Supervisor, ['agencia_id' => $agencia->id, 'email' => 'sup@xyz.test']);
        $promotor = $this->criarUsuario($this->tenant, Perfil::Promotor, ['agencia_id' => $agencia->id, 'tipo_vinculo' => 'clt', 'email' => 'p@xyz.test']);

        $this->tenant->run(function () use ($agencia, $promotor) {
            $marca = Industria::create(['nome' => 'Marca Aurora']);
            $sku = Sku::create(['industria_id' => $marca->id, 'codigo' => 'ALG-25', 'descricao' => 'Alvejante']);
            $pdv = Pdv::create(['cnpj' => self::cnpj(1), 'nome' => 'Loja Centro']);
            $r = Roteiro::create(['promotor_id' => $promotor->id, 'agencia_id' => $agencia->id, 'data' => '2026-10-31']);
            $v = Visita::create(['roteiro_id' => $r->id, 'data' => '2026-10-31', 'pdv_id' => $pdv->id, 'industria_id' => $marca->id, 'agencia_id' => $agencia->id, 'origem' => 'demanda', 'status' => 'realizada']);
            $p = ModelosDePartida::todos()['preco'];
            $m = ModeloColeta::create(['industria_id' => $marca->id, 'nome' => 'Preço', 'tipo' => 'preco', 'campos' => Formulario::normalizarCampos($p['campos']), 'versao' => 1]);
            $m->registrarVersao();
            ColetaResposta::create(['uuid' => (string) Str::uuid(), 'visita_id' => $v->id, 'modelo_id' => $m->id, 'versao' => 1, 'sku_id' => $sku->id,
                'respostas' => [$m->chaveDoPapel('preco_proprio') => 7.5], 'respondida_em' => now(), 'recebida_em' => now(), 'hash' => Str::random(64)]);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pede_exportacao_de_coletas_e_baixa_quando_pronta(): void
    {
        $this->actingAs($this->admin)->post(self::URL.'/exportacoes', ['tipo' => 'coletas', 'relatorio' => 'preco', 'inicio' => '2026-10-01', 'fim' => '2026-10-31'])
            ->assertRedirect(self::URL.'/exportacoes');

        $e = $this->tenant->run(fn () => Exportacao::sole());
        $this->assertSame('pronto', $e->estado);
        $this->assertSame('coletas-preco.csv', $e->nome);
        $this->assertTrue($e->expira_em->isSameDay(now()->addDays(7)));
        $this->assertTrue(Storage::disk('local')->exists($e->arquivo));

        $this->get(self::URL.'/exportacoes')->assertOk()->assertSee('Pronta')->assertSee('coletas-preco.csv')->assertSee('Baixar');
        $csv = $this->get(self::URL."/exportacoes/{$e->id}/baixar")->assertOk()->streamedContent();
        $this->assertStringContainsString('ALG-25 · Alvejante', $csv);
        $this->assertStringContainsString('7.5', $csv);
    }

    public function test_exportacao_de_indicadores_gera_o_excel(): void
    {
        $this->actingAs($this->admin)->post(self::URL.'/exportacoes', ['tipo' => 'indicadores', 'inicio' => '2026-10-01', 'fim' => '2026-10-31'])->assertRedirect();

        $e = $this->tenant->run(fn () => Exportacao::sole());
        $this->assertSame('pronto', $e->estado);
        $this->assertStringEndsWith('.xlsx', $e->nome);
        $caminho = Storage::disk('local')->path($e->arquivo);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($caminho));
        $this->assertNotFalse($zip->locateName('xl/workbook.xml'), 'é uma planilha xlsx de verdade');
        $zip->close();
    }

    public function test_so_o_dono_baixa_e_os_dados_seguem_o_escopo_dele(): void
    {
        $this->actingAs($this->admin)->post(self::URL.'/exportacoes', ['tipo' => 'coletas', 'relatorio' => 'preco']);
        $e = $this->tenant->run(fn () => Exportacao::sole());

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->supervisor)->get(self::URL.'/exportacoes')->assertOk()->assertDontSee('coletas-preco.csv');
        $this->get(self::URL."/exportacoes/{$e->id}/baixar")->assertNotFound();
        $this->delete(self::URL."/exportacoes/{$e->id}")->assertNotFound();

        // O supervisor gera a dele: o escopo é o da agência dele, que tem a visita.
        $this->post(self::URL.'/exportacoes', ['tipo' => 'coletas', 'relatorio' => 'preco'])->assertRedirect();
        $minha = $this->tenant->run(fn () => Exportacao::where('usuario_id', $this->supervisor->id)->sole());
        $this->assertSame('supervisor', $minha->parametros['usuario']['perfil']);
        $this->get(self::URL."/exportacoes/{$minha->id}/baixar")->assertOk();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->criarUsuario($this->tenant, Perfil::Promotor, ['email' => 'p2@xyz.test']))->get(self::URL.'/exportacoes')->assertForbidden();
    }

    public function test_validacao_e_erro_do_job_ficam_registrados(): void
    {
        $this->actingAs($this->admin);
        $this->post(self::URL.'/exportacoes', ['tipo' => 'inventado'])->assertSessionHasErrors('tipo');
        $this->post(self::URL.'/exportacoes', ['tipo' => 'coletas', 'relatorio' => 'x'])->assertSessionHasErrors('relatorio');

        $e = $this->tenant->run(fn () => Exportacao::create(['uuid' => (string) Str::uuid(), 'agencia_id' => null, 'usuario_id' => $this->admin->id, 'tipo' => 'coletas',
            'parametros' => ['filtros' => ['inicio' => '2026-10-01', 'fim' => '2026-10-31'], 'relatorio' => 'preco']])); // sem o escopo do usuário: o job registra o erro
        $this->tenant->run(fn () => (new GerarExportacao($e->id))->handle(app(ExcelIndicadores::class), app(ResultadosColeta::class), app(BookDeFotos::class)));
        $e = $this->tenant->run(fn () => $e->fresh());
        $this->assertSame('erro', $e->estado);
        $this->assertNotEmpty($e->erro);
        $this->get(self::URL."/exportacoes/{$e->id}/baixar")->assertNotFound();
    }

    public function test_arquivo_expirado_nao_baixa_e_a_limpeza_apaga(): void
    {
        $this->actingAs($this->admin)->post(self::URL.'/exportacoes', ['tipo' => 'coletas', 'relatorio' => 'preco']);
        $e = $this->tenant->run(fn () => Exportacao::sole());
        $arquivo = $e->arquivo;

        Carbon::setTestNow('2026-11-09 12:00:00'); // 9 dias depois
        $this->get(self::URL."/exportacoes/{$e->id}/baixar")->assertNotFound();
        $this->get(self::URL.'/exportacoes')->assertSee('Expirada');

        Artisan::call('exportacoes:limpar');
        $this->assertSame(0, $this->tenant->run(fn () => Exportacao::count()));
        $this->assertFalse(Storage::disk('local')->exists($arquivo));
    }

    public function test_book_de_fotos_gera_zip_com_indice_e_respeita_o_escopo(): void
    {
        $this->tenant->run(function () {
            $v = Visita::first();
            Storage::disk('local')->put('visitas/1/a.jpg', 'conteudo-da-foto-a');
            Storage::disk('local')->put('visitas/1/b.jpg', 'conteudo-da-foto-b');
            VisitaFoto::create(['visita_id' => $v->id, 'uuid' => (string) Str::uuid(), 'tipo' => 'antes', 'caminho' => 'visitas/1/a.jpg', 'hash_origem' => Str::random(64), 'metadados' => []]);
            $f = VisitaFoto::create(['visita_id' => $v->id, 'uuid' => (string) Str::uuid(), 'tipo' => 'depois', 'caminho' => 'visitas/1/b.jpg', 'hash_origem' => Str::random(64), 'metadados' => []]);
            VisitaFoto::create(['visita_id' => $v->id, 'uuid' => (string) Str::uuid(), 'tipo' => 'ruptura', 'caminho' => 'visitas/1/nao-existe.jpg', 'hash_origem' => Str::random(64), 'metadados' => []]);
            FotoAvaliacao::create(['foto_id' => $f->id, 'agencia_id' => null, 'usuario_id' => 1, 'usuario_nome' => 'X', 'nota' => 5]);
        });

        $this->actingAs($this->admin)->get(self::URL.'/indicadores/galeria?inicio=2026-10-01&fim=2026-10-31')->assertOk()->assertSee('Gerar book de fotos (ZIP)');
        $this->post(self::URL.'/exportacoes', ['tipo' => 'book', 'inicio' => '2026-10-01', 'fim' => '2026-10-31'])->assertRedirect();

        $e = $this->tenant->run(fn () => Exportacao::sole());
        $this->assertSame('pronto', $e->estado);
        $this->assertSame('book-fotos-2026-10-01-2026-10-31.zip', $e->nome);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($e->arquivo)));
        $this->assertSame('conteudo-da-foto-a', $zip->getFromName('loja-centro/2026-10-31-v1-antes-1.jpg'));
        $this->assertNotFalse($zip->locateName('loja-centro/2026-10-31-v1-depois-2.jpg'));
        $this->assertFalse($zip->locateName('loja-centro/2026-10-31-v1-ruptura-3.jpg'), 'foto sem arquivo é ignorada');
        $indice = (string) $zip->getFromName('indice.csv');
        $this->assertStringContainsString('Loja Centro', $indice);
        $this->assertStringContainsString(';5', $indice, 'nota média no índice');
        $this->assertStringContainsString('2 foto(s)', (string) $zip->getFromName('LEIA-ME.txt'));
        $zip->close();
    }
}
