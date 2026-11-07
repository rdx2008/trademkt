<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\Alerta;
use App\Models\ContratoVisita;
use App\Models\Demanda;
use App\Models\Industria;
use App\Models\Pdv;
use App\Models\Roteiro;
use App\Models\Sku;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visita;
use App\Models\VisitaFoto;
use App\Services\Operacao\EncerrarDia;
use App\Services\Operacao\Indicadores;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperacaoTest extends TestCase
{
    private Tenant $tenant;

    private Agencia $agencia;

    private Industria $marca;

    private Pdv $pdv;

    private ContratoVisita $contrato;

    private Sku $sku;

    private User $promotor;

    private User $supervisor;

    private User $gerente;

    private User $trade;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');
        config(['filesystems.default' => 'local']);
        $this->tenant = $this->criarCliente('xyz', 'agencia');
        [$this->agencia, $this->marca, $this->pdv, $this->contrato, $this->sku] = $this->tenant->run(function () {
            $agencia = Agencia::first();
            $marca = Industria::create(['nome' => 'Marca Aurora']);
            $pdv = Pdv::create(['cnpj' => self::cnpj(1), 'nome' => 'Mercado Centro', 'cidade' => 'Caxias do Sul', 'uf' => 'RS', 'lat' => -29.168, 'lng' => -51.179]);
            $contrato = ContratoVisita::create(['pdv_id' => $pdv->id, 'industria_id' => $marca->id, 'agencia_id' => $agencia->id, 'frequencia_semana' => 1, 'dias' => [6], 'inicio' => '2026-01-01']);

            return [$agencia, $marca, $pdv, $contrato, Sku::create(['industria_id' => $marca->id, 'codigo' => 'A1', 'descricao' => 'Produto Aurora'])];
        });
        $this->promotor = $this->criarUsuario($this->tenant, Perfil::Promotor, ['nome' => 'João Silva', 'agencia_id' => $this->agencia->id, 'tipo_vinculo' => 'freelancer']);
        $this->supervisor = $this->criarUsuario($this->tenant, Perfil::Supervisor, ['agencia_id' => $this->agencia->id]);
        $this->gerente = $this->criarUsuario($this->tenant, Perfil::GerentePdv);
        $this->trade = $this->criarUsuario($this->tenant, Perfil::GerenteTrade, ['industria_id' => $this->marca->id]);
        $this->tenant->run(fn () => $this->gerente->pdvsGerenciados()->attach($this->pdv->id));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function visita(array $extra = []): Visita
    {
        return $this->tenant->run(function () use ($extra) {
            $data = $extra['data'] ?? today()->toDateString();
            $r = Roteiro::firstOrCreate(['promotor_id' => $this->promotor->id, 'data' => $data], ['supervisor_id' => $this->supervisor->id]);

            return Visita::create(array_merge(['roteiro_id' => $r->id, 'data' => $data, 'pdv_id' => $this->pdv->id, 'industria_id' => $this->marca->id, 'agencia_id' => $this->agencia->id, 'origem' => 'demanda'], $extra));
        });
    }

    private function app(?User $u = null): void
    {
        Sanctum::actingAs($u ?? $this->promotor, ['promotor']);
    }

    private function url(Visita $v, string $acao): string
    {
        return 'http://xyz.test/api/v1/visitas/'.$v->id.'/'.$acao;
    }

    private function gps(string $hora = '09:00:00'): array
    {
        return ['lat' => -29.168, 'lng' => -51.179, 'gps_em' => '2026-10-31T'.$hora.'-03:00', 'aparelho_em' => '2026-10-31T'.$hora.'-03:00', 'gps_simulado' => false];
    }

    private function iniciar(Visita $v): void
    {
        $this->app();
        $this->postJson($this->url($v, 'check-in'), $this->gps())->assertCreated();
    }

    private function foto(Visita $v, string $tipo, string $hora, array $extra = [])
    {
        return $this->post($this->url($v, 'fotos'), array_merge($this->gps($hora), ['uuid' => (string) Str::uuid(), 'tipo' => $tipo, 'foto' => UploadedFile::fake()->image('foto.jpg', 400, 300)], $extra), ['Accept' => 'application/json']);
    }

    private function finalizacao(string $tipo = 'normal'): array
    {
        return ['uuid' => (string) Str::uuid(), 'tipo' => $tipo, 'finalizada_em' => '2026-10-31T09:30:00-03:00', 'observacao' => $tipo === 'impedimento' ? 'Acesso ao estoque impedido pelo responsável.' : '', 'itens' => $tipo === 'impedimento' ? [] : [['sku_id' => $this->sku->id, 'estoque' => 'tem_estoque']]];
    }

    private function executar(Visita $v): void
    {
        $this->iniciar($v);
        $this->foto($v, 'antes', '09:05:00')->assertCreated();
        $this->foto($v, 'depois', '09:20:00')->assertCreated();
        $this->postJson($this->url($v, 'concluir'), $this->finalizacao())->assertCreated();
    }

    private function pedir(array $extra = []): Demanda
    {
        $this->actingAs($this->gerente, 'web')->post('http://xyz.test/demandas', array_merge(['uuid' => (string) Str::uuid(), 'contrato_id' => $this->contrato->id, 'tipo' => 'visita', 'motivo' => 'desarrumacao'], $extra))->assertRedirect()->assertSessionHasNoErrors();

        return $this->tenant->run(fn () => Demanda::latest('id')->first());
    }

    public function test_fluxo_completo_fotos_marcadas_e_conclusao_idempotente(): void
    {
        $v = $this->visita();
        $this->iniciar($v);
        $antes = $this->foto($v, 'antes', '09:05:00')->assertCreated()->json('foto.id');
        $this->foto($v, 'depois', '09:20:00')->assertCreated();
        $dados = $this->finalizacao();
        $this->postJson($this->url($v, 'concluir'), $dados)->assertCreated()->assertJsonPath('visita.status', 'realizada')->assertJsonPath('visita.duracao_segundos', 1800)->assertJsonMissingPath('visita.finalizacao_hash');
        $this->postJson($this->url($v, 'concluir'), $dados)->assertOk();
        $this->postJson($this->url($v, 'concluir'), array_merge($dados, ['observacao' => 'Alterada']))->assertConflict();
        $this->tenant->run(function () use ($v, $antes) {
            $this->assertSame(1, $v->itens()->count());
            $f = VisitaFoto::find($antes);
            $bytes = Storage::disk($f->disco)->get($f->caminho);
            $this->assertSame('image/jpeg', getimagesizefromstring($bytes)['mime']);
            $this->assertSame(390, getimagesizefromstring($bytes)[1]);
            $this->assertFalse($f->metadados['relogio_divergente']);
            $this->assertStringStartsWith('clientes/xyz/', $f->caminho);
            $t = app(Indicadores::class)->tempos($this->trade, ['inicio' => '2026-10-31', 'fim' => '2026-10-31'])->first();
            $this->assertEquals(30, $t->minutos);
            $this->assertEquals(0, $t->impedimentos);
        });
        $this->getJson('http://xyz.test/api/v1/visitas/'.$v->id)->assertOk()->assertJsonCount(2, 'visita.fotos')->assertJsonMissingPath('visita.fotos.0.caminho');
    }

    public function test_foto_reenviada_nao_duplica_e_mesmo_uuid_com_outros_dados_e_recusado(): void
    {
        $v = $this->visita();
        $this->iniciar($v);
        $dados = array_merge($this->gps('09:05:00'), ['uuid' => (string) Str::uuid(), 'tipo' => 'antes', 'foto' => UploadedFile::fake()->image('a.jpg', 200, 200)]);
        $this->post($this->url($v, 'fotos'), $dados, ['Accept' => 'application/json'])->assertCreated();
        $this->post($this->url($v, 'fotos'), $dados, ['Accept' => 'application/json'])->assertOk();
        $this->post($this->url($v, 'fotos'), array_merge($dados, ['lat' => -29.1681]), ['Accept' => 'application/json'])->assertConflict();
        $this->tenant->run(fn () => $this->assertSame(1, $v->fotos()->count()));
    }

    public function test_ruptura_conta_como_visita_mas_nao_como_sku_trabalhado(): void
    {
        $v = $this->visita();
        $this->iniciar($v);
        $this->foto($v, 'ruptura', '09:10:00')->assertCreated();
        $this->postJson($this->url($v, 'concluir'), $this->finalizacao('ruptura'))->assertCreated()->assertJsonPath('visita.status', 'realizada');
        $this->tenant->run(function () {
            $f = ['inicio' => '2026-10-31', 'fim' => '2026-10-31'];
            $r = app(Indicadores::class)->resumo($this->trade, $f);
            $this->assertSame(1, $r['rupturas']);
            $this->assertSame(0, $r['skus_trabalhados']);
            $this->assertSame(1, $r['realizadas']);
            $this->assertTrue(Alerta::where('usuario_id', $this->supervisor->id)->where('tipo', 'ruptura')->exists());
        });
    }

    public function test_impedimento_exige_check_in_e_observacao(): void
    {
        $v = $this->visita();
        $this->app();
        $dados = $this->finalizacao('impedimento');
        $this->postJson($this->url($v, 'concluir'), $dados)->assertConflict();
        $this->iniciar($v);
        $this->postJson($this->url($v, 'concluir'), array_merge($dados, ['observacao' => '']))->assertUnprocessable();
        $this->postJson($this->url($v, 'concluir'), $dados)->assertCreated()->assertJsonPath('visita.status', 'em_atencao');
        $this->tenant->run(fn () => $this->assertTrue(Alerta::where('tipo', 'impedimento')->exists()));
    }

    public function test_recusa_sku_de_outra_industria_e_falta_de_fotos(): void
    {
        $v = $this->visita();
        $this->iniciar($v);
        $this->postJson($this->url($v, 'concluir'), $this->finalizacao())->assertUnprocessable()->assertJsonValidationErrors('fotos');
        $outro = $this->tenant->run(fn () => Sku::create(['industria_id' => Industria::create(['nome' => 'Outra'])->id, 'codigo' => 'B1', 'descricao' => 'Reservado']));
        $d = $this->finalizacao();
        $d['itens'][0]['sku_id'] = $outro->id;
        $this->postJson($this->url($v, 'concluir'), $d)->assertUnprocessable()->assertJsonValidationErrors('itens');
        $this->tenant->run(fn () => $this->assertSame('em_andamento', $v->fresh()->status->value));
    }

    public function test_ordem_das_fotos_horarios_e_gps_sao_validados(): void
    {
        $v = $this->visita();
        $this->iniciar($v);
        $this->foto($v, 'depois', '09:10:00')->assertUnprocessable();
        $this->foto($v, 'antes', '08:00:00')->assertUnprocessable();
        $this->foto($v, 'antes', '09:05:00', ['lat' => -30])->assertUnprocessable();
        $this->foto($v, 'antes', '09:05:00', ['gps_simulado' => true])->assertUnprocessable();
        $this->foto($v, 'antes', '09:05:00')->assertCreated();
        $this->foto($v, 'depois', '09:00:00')->assertUnprocessable();
        $this->foto($v, 'depois', '09:20:00')->assertCreated();
        $this->foto($v, 'antes', '09:25:00')->assertUnprocessable();
        $this->postJson($this->url($v, 'concluir'), array_merge($this->finalizacao(), ['finalizada_em' => '2026-10-31T09:10:00-03:00']))->assertUnprocessable();
    }

    public function test_fotos_so_podem_ser_vistas_no_escopo_do_usuario(): void
    {
        $v = $this->visita();
        $this->iniciar($v);
        $foto = $this->foto($v, 'antes', '09:05:00')->assertCreated()->json('foto.id');
        $this->get('http://xyz.test/api/v1/visitas/'.$v->id.'/fotos/'.$foto)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $outro = $this->criarUsuario($this->tenant, Perfil::Promotor, ['email' => 'outro@xyz.test', 'agencia_id' => $this->agencia->id]);
        $this->app($outro);
        $this->getJson('http://xyz.test/api/v1/visitas/'.$v->id.'/fotos/'.$foto)->assertNotFound();
        $this->foto($v, 'antes', '09:10:00')->assertNotFound();
        $this->actingAs($this->gerente, 'web')->get('http://xyz.test/visitas/'.$v->id)->assertOk();
    }

    public function test_gerente_pede_visita_e_so_agencia_aprova_sem_duplicar(): void
    {
        $d = $this->pedir();
        $this->actingAs($this->trade, 'web')->patchJson('http://xyz.test/demandas/'.$d->id, ['decisao' => 'aprovar', 'promotor_id' => $this->promotor->id])->assertForbidden();
        $this->actingAs($this->supervisor, 'web')->patch('http://xyz.test/demandas/'.$d->id, ['decisao' => 'aprovar', 'promotor_id' => $this->promotor->id])->assertSessionHasNoErrors();
        $this->patch('http://xyz.test/demandas/'.$d->id, ['decisao' => 'aprovar', 'promotor_id' => $this->promotor->id])->assertSessionHasNoErrors();
        $v = $this->tenant->run(fn () => $d->fresh()->visita);
        $this->assertSame('demanda', $v->origem->value);
        $this->tenant->run(fn () => $this->assertSame(1, Visita::count()));
        $this->executar($v);
        $this->tenant->run(function () use ($d) {
            $this->assertSame('atendida', $d->fresh()->status);
            $this->assertFalse($d->fresh()->atrasada());
        });
    }

    public function test_pedido_e_idempotente_e_nao_permite_outro_contrato_ou_sku(): void
    {
        $uuid = (string) Str::uuid();
        $d = $this->pedir(['uuid' => $uuid]);
        $this->pedir(['uuid' => $uuid]);
        $this->tenant->run(fn () => $this->assertSame(1, Demanda::count()));
        $this->postJson('http://xyz.test/demandas', ['uuid' => $uuid, 'contrato_id' => $this->contrato->id, 'tipo' => 'visita', 'motivo' => 'outro', 'observacao' => 'Outra mensagem'])->assertConflict();
        $this->postJson('http://xyz.test/demandas', ['uuid' => (string) Str::uuid(), 'contrato_id' => 99999, 'tipo' => 'visita', 'motivo' => 'desarrumacao'])->assertNotFound();
        $this->postJson('http://xyz.test/demandas', ['uuid' => (string) Str::uuid(), 'contrato_id' => $this->contrato->id, 'tipo' => 'ruptura', 'motivo' => 'ruptura', 'skus' => [99999]])->assertUnprocessable();
    }

    public function test_recusa_precisa_de_motivo_e_agencia_nao_atribui_a_promotor_de_outra(): void
    {
        $d = $this->pedir();
        $outro = $this->tenant->run(fn () => Agencia::create(['nome' => 'Reservada']));
        $p = $this->criarUsuario($this->tenant, Perfil::Promotor, ['email' => 'reservado@xyz.test', 'agencia_id' => $outro->id]);
        $this->actingAs($this->supervisor, 'web')->patchJson('http://xyz.test/demandas/'.$d->id, ['decisao' => 'aprovar', 'promotor_id' => $p->id])->assertUnprocessable();
        $this->patchJson('http://xyz.test/demandas/'.$d->id, ['decisao' => 'recusar'])->assertUnprocessable();
        $this->patch('http://xyz.test/demandas/'.$d->id, ['decisao' => 'recusar', 'resposta' => 'Equipe indisponível'])->assertSessionHasNoErrors();
        $this->tenant->run(fn () => $this->assertSame('recusada', $d->fresh()->status));
    }

    public function test_alertas_sao_individuais_e_representante_so_recebe_da_carteira(): void
    {
        $com = $this->criarUsuario($this->tenant, Perfil::Representante, ['industria_id' => $this->marca->id]);
        $sem = $this->criarUsuario($this->tenant, Perfil::Representante, ['email' => 'sem@xyz.test', 'industria_id' => $this->marca->id]);
        $this->tenant->run(fn () => $com->carteiraPdvs()->attach($this->pdv->id));
        $this->pedir();
        [$a,$count] = $this->tenant->run(fn () => [Alerta::where('usuario_id', $com->id)->first(), Alerta::where('usuario_id', $sem->id)->count()]);
        $this->assertNotNull($a);
        $this->assertSame(0, $count);
        $this->actingAs($sem, 'web')->patchJson('http://xyz.test/alertas/'.$a->id.'/lida')->assertNotFound();
        $this->actingAs($com, 'web')->patch('http://xyz.test/alertas/'.$a->id.'/lida')->assertRedirect();
        $this->tenant->run(fn () => $this->assertNotNull($a->fresh()->lida_em));
    }

    public function test_encerramento_idempotente_e_sincronizacao_tardia(): void
    {
        $v = $this->visita();
        $this->pedir();
        Carbon::setTestNow('2026-11-01 12:00:00');
        $this->tenant->run(function () use ($v) {
            $primeiro = app(EncerrarDia::class)->executar();
            $contagem = Alerta::count();
            $segundo = app(EncerrarDia::class)->executar();
            $this->assertSame(['visitas' => 1, 'demandas' => 1], $primeiro);
            $this->assertSame(['visitas' => 0, 'demandas' => 0], $segundo);
            $this->assertSame($contagem, Alerta::count());
            $this->assertSame('nao_executada', $v->fresh()->status->value);
        });
        $this->executar($v);
        $this->tenant->run(fn () => $this->assertSame('realizada', $v->fresh()->status->value));
    }

    public function test_indicadores_producao_cobertura_excel_e_filtros(): void
    {
        $v = $this->visita(['origem' => 'contrato', 'contrato_id' => $this->contrato->id, 'data_prevista' => '2026-10-31']);
        $this->executar($v);
        $this->actingAs($this->trade, 'web')->get('http://xyz.test/indicadores?inicio=2026-10-31&fim=2026-10-31')->assertOk()->assertViewHas('resumo', fn ($r) => $r['cobertura'] === 100.0 && $r['skus_trabalhados'] === 1 && $r['tempo_medio_min'] === 30.0);
        $this->get('http://xyz.test/indicadores/galeria?inicio=2026-10-31&fim=2026-10-31')->assertOk()->assertSee('Mercado Centro');
        $this->getJson('http://xyz.test/indicadores?industria_id=9999')->assertUnprocessable();
        $this->getJson('http://xyz.test/indicadores?inicio=2020-01-01&fim=2026-10-31')->assertUnprocessable();
        $excel = $this->get('http://xyz.test/indicadores/excel?inicio=2026-10-31&fim=2026-10-31')->assertOk();
        $arquivo = $excel->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($arquivo));
        $this->assertNotFalse($zip->locateName('xl/workbook.xml'));
        $zip->close();
        @unlink($arquivo);
    }

    public function test_isolamento_de_demandas_indicadores_e_fotos_entre_agencias(): void
    {
        $v = $this->visita();
        $this->executar($v);
        $d = $this->pedir();
        $outra = $this->tenant->run(fn () => Agencia::create(['nome' => 'Outra agência']));
        $u = $this->criarUsuario($this->tenant, Perfil::AdminAgencia, ['agencia_id' => $outra->id]);
        $this->actingAs($u, 'web')->getJson('http://xyz.test/visitas/'.$v->id)->assertNotFound();
        $this->getJson('http://xyz.test/demandas/'.$d->id)->assertNotFound();
        $this->get('http://xyz.test/indicadores')->assertOk()->assertViewHas('resumo', fn ($r) => $r['realizadas'] === 0 && $r['demandas'] === 0);
    }

    public function test_multiplas_demandas_usam_o_mesmo_roteiro_do_dia(): void
    {
        $a = $this->pedir();
        $b = $this->pedir();
        foreach ([$a, $b] as $d) {
            $this->actingAs($this->supervisor, 'web')->patch('http://xyz.test/demandas/'.$d->id, ['decisao' => 'aprovar', 'promotor_id' => $this->promotor->id])->assertSessionHasNoErrors();
        }
        $this->tenant->run(function () {
            $this->assertSame(1, Roteiro::count());
            $this->assertSame(2, Visita::count());
        });
    }

    public function test_alerta_deixa_de_aparecer_quando_o_usuario_perde_o_vinculo_com_a_loja(): void
    {
        $this->pedir();
        $a = $this->tenant->run(fn () => Alerta::where('usuario_id', $this->gerente->id)->first());
        $this->assertNotNull($a);
        $this->tenant->run(fn () => $this->gerente->pdvsGerenciados()->detach());
        $this->actingAs($this->gerente, 'web')->get('http://xyz.test/alertas')->assertOk()->assertViewHas('naoLidas', 0);
        $this->patchJson('http://xyz.test/alertas/'.$a->id.'/lida')->assertNotFound();
    }

    public function test_ruptura_relatada_pelo_gerente_tem_foto_opcional_e_alerta(): void
    {
        $d = $this->pedir(['tipo' => 'ruptura', 'motivo' => 'ruptura', 'skus' => [$this->sku->id], 'foto' => UploadedFile::fake()->image('relato.png', 300, 300)]);
        $this->assertSame('ruptura', $d->tipo);
        $this->get('http://xyz.test/demandas/'.$d->id)->assertOk()->assertSee('Produto Aurora');
        $this->get('http://xyz.test/demandas/'.$d->id.'/foto')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->tenant->run(function () {
            $r = app(Indicadores::class)->resumo($this->trade, ['inicio' => '2026-10-31', 'fim' => '2026-10-31']);
            $this->assertSame(1, $r['relatos_ruptura']);
            $this->assertSame(0, $r['rupturas']);
        });
    }

    public function test_foto_com_coordenadas_inteiras_e_relogio_divergente_pode_ser_reenviada(): void
    {
        $this->tenant->run(fn () => $this->pdv->update(['lat' => 0, 'lng' => 0]));
        $v = $this->visita();
        $this->app();
        $this->postJson($this->url($v, 'check-in'), array_merge($this->gps(), ['lat' => 0, 'lng' => 0]))->assertCreated();
        $dados = array_merge($this->gps('09:05:00'), ['lat' => 0, 'lng' => 0, 'aparelho_em' => '2026-10-31T10:05:00-03:00', 'uuid' => (string) Str::uuid(), 'tipo' => 'antes', 'foto' => UploadedFile::fake()->image('f.jpg', 200, 200)]);
        $this->post($this->url($v, 'fotos'), $dados, ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('foto.metadados.relogio_divergente', true);
        $this->post($this->url($v, 'fotos'), $dados, ['Accept' => 'application/json'])->assertOk();
    }

    public function test_falha_no_banco_remove_foto_que_acabou_de_ser_gravada(): void
    {
        $v = $this->visita();
        $this->iniciar($v);
        VisitaFoto::creating(fn () => throw new \RuntimeException('Falha simulada'));
        try {
            $this->withoutExceptionHandling()->foto($v, 'antes', '09:05:00');
            $this->fail('Deveria falhar ao inserir a foto.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Falha simulada', $e->getMessage());
        } finally {
            VisitaFoto::flushEventListeners();
        }
        $this->tenant->run(function () use ($v) {
            $this->assertSame(0, $v->fotos()->count());
            $this->assertSame([], Storage::disk('local')->allFiles('clientes/xyz/visitas/'.$v->id));
        });
    }

    public function test_atendimento_offline_no_prazo_nao_e_contado_como_atrasado_ao_sincronizar(): void
    {
        $d = $this->pedir();
        $this->actingAs($this->supervisor, 'web')->patch('http://xyz.test/demandas/'.$d->id, ['decisao' => 'aprovar', 'promotor_id' => $this->promotor->id])->assertSessionHasNoErrors();
        $v = $this->tenant->run(fn () => $d->fresh()->visita);
        Carbon::setTestNow('2026-11-01 12:00:00');
        $this->tenant->run(fn () => app(EncerrarDia::class)->executar());
        $this->executar($v);
        $this->tenant->run(function () use ($d) {
            $this->assertFalse($d->fresh()->atrasada());
            $r = app(Indicadores::class)->resumo($this->trade, ['inicio' => '2026-10-31', 'fim' => '2026-10-31']);
            $this->assertSame(1, $r['demandas_no_prazo']);
            $this->assertSame(0, $r['demandas_atrasadas']);
        });
    }

    public function test_excel_trata_nomes_como_texto_e_painel_do_gerente_mostra_ultima_visita(): void
    {
        $this->tenant->run(fn () => $this->sku->update(['descricao' => '=2+2']));
        $v = $this->visita();
        $this->executar($v);
        $this->actingAs($this->gerente, 'web')->get('http://xyz.test/painel')->assertOk()->assertSee('Última visita:')->assertSee('Pedir visita')->assertSee('Avisar ruptura');
        $excel = $this->get('http://xyz.test/indicadores/excel?inicio=2026-10-31&fim=2026-10-31')->assertOk();
        $path = $excel->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nome = $zip->getNameIndex($i);
            if (str_starts_with($nome, 'xl/worksheets/') || $nome === 'xl/sharedStrings.xml') {
                $xml .= $zip->getFromIndex($i);
            }
        }
        $this->assertStringContainsString('=2+2', $xml);
        $this->assertDoesNotMatchRegularExpression('/<f(?:>|\s)/', $xml);
        $zip->close();
        @unlink($path);
    }
}
