<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\Alerta;
use App\Models\Industria;
use App\Models\Pdv;
use App\Models\Roteiro;
use App\Models\Sku;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visita;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * O check-in é a foto da gôndola antes: ela abre a visita, e hora e localização da foto viram
 * o check-in. No impedimento de acesso, a foto da entrada faz o mesmo papel.
 */
class CheckInPelaFotoTest extends TestCase
{
    private Tenant $tenant;

    private Agencia $agencia;

    private Industria $marca;

    private Pdv $pdv;

    private Sku $sku;

    private User $promotor;

    private Visita $visita;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');
        Storage::fake('local');
        $this->tenant = $this->criarCliente('xyz', 'agencia');
        [$this->agencia, $this->marca, $this->pdv, $this->sku] = $this->tenant->run(function () {
            $agencia = Agencia::first();
            $marca = Industria::create(['nome' => 'Marca Aurora']);
            $pdv = Pdv::create(['cnpj' => self::cnpj(1), 'nome' => 'Mercado Centro', 'cidade' => 'Caxias do Sul', 'uf' => 'RS', 'lat' => -29.168, 'lng' => -51.179]);

            return [$agencia, $marca, $pdv, Sku::create(['industria_id' => $marca->id, 'codigo' => 'A1', 'descricao' => 'Produto'])];
        });
        $this->promotor = $this->criarUsuario($this->tenant, Perfil::Promotor, ['agencia_id' => $this->agencia->id, 'tipo_vinculo' => 'clt']);
        $this->visita = $this->nova();
        Sanctum::actingAs($this->promotor, ['promotor']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function nova(string $status = 'previsto', string $data = '2026-10-31'): Visita
    {
        return $this->tenant->run(function () use ($status, $data) {
            $r = Roteiro::firstOrCreate(['promotor_id' => $this->promotor->id, 'agencia_id' => null, 'data' => $data]);

            return Visita::create(['roteiro_id' => $r->id, 'data' => $data, 'pdv_id' => $this->pdv->id, 'industria_id' => $this->marca->id, 'agencia_id' => $this->agencia->id, 'origem' => 'demanda', 'status' => $status]);
        });
    }

    private function url(string $acao, ?Visita $v = null): string
    {
        return 'http://xyz.test/api/v1/visitas/'.($v ?? $this->visita)->id.'/'.$acao;
    }

    private function gps(string $hora = '09:00:00', array $extra = []): array
    {
        return array_merge(['lat' => -29.168, 'lng' => -51.179, 'gps_em' => '2026-10-31T'.$hora.'-03:00', 'aparelho_em' => '2026-10-31T'.$hora.'-03:00', 'gps_simulado' => false], $extra);
    }

    private function foto(string $tipo, string $hora = '09:00:00', array $extra = [], ?Visita $v = null)
    {
        return $this->post($this->url('fotos', $v), array_merge($this->gps($hora), ['uuid' => (string) Str::uuid(), 'tipo' => $tipo, 'foto' => UploadedFile::fake()->image('foto.jpg', 400, 300)], $extra), ['Accept' => 'application/json']);
    }

    private function visitaAtual(?Visita $v = null): Visita
    {
        return $this->tenant->run(fn () => Visita::find(($v ?? $this->visita)->id));
    }

    public function test_foto_antes_abre_a_visita_com_hora_e_localizacao_da_foto(): void
    {
        $resposta = $this->foto('antes', '09:05:00', ['lat' => -29.1682, 'lng' => -51.1791])->assertCreated();
        $resposta->assertJsonPath('status', 'em_andamento')->assertJsonPath('check_in.origem', 'foto')
            ->assertJsonPath('check_in.lat', -29.1682)->assertJsonPath('check_in.lng', -51.1791)->assertJsonPath('check_in.raio_m', 200)
            ->assertJsonPath('check_in.foto_uuid', $resposta->json('foto.uuid'));
        $this->assertGreaterThan(0, $resposta->json('check_in.distancia_m'));

        $v = $this->visitaAtual();
        $this->assertSame('em_andamento', $v->status->value);
        $this->assertSame('2026-10-31T09:05:00-03:00', Carbon::parse($v->check_in['gps_em'])->toIso8601String());
        $this->assertSame(Carbon::parse($v->check_in['gps_em'])->getTimestamp(), Carbon::parse($v->fotos()->sole()->metadados['gps_em'])->getTimestamp());
        $this->assertFalse($v->check_in['relogio_divergente']);
    }

    public function test_reenvio_da_mesma_foto_nao_duplica_nem_muda_o_check_in(): void
    {
        $uuid = (string) Str::uuid();
        $arquivo = UploadedFile::fake()->image('foto.jpg', 400, 300);
        $dados = $this->gps('09:05:00') + ['uuid' => $uuid, 'tipo' => 'antes', 'foto' => $arquivo];
        $this->post($this->url('fotos'), $dados, ['Accept' => 'application/json'])->assertCreated();
        $primeiro = $this->visitaAtual()->check_in;

        $this->post($this->url('fotos'), $dados, ['Accept' => 'application/json'])->assertOk();
        $this->assertSame($primeiro, $this->visitaAtual()->check_in);
        $this->tenant->run(fn () => $this->assertSame(1, Visita::find($this->visita->id)->fotos()->count()));
    }

    public function test_foto_antes_fora_do_raio_ou_gps_simulado_nao_abre_a_visita(): void
    {
        $this->foto('antes', '09:00:00', ['lat' => -29.2, 'lng' => -51.3])->assertUnprocessable()->assertJsonValidationErrors('lat');
        $this->foto('antes', '09:00:00', ['gps_simulado' => true])->assertUnprocessable();
        $this->foto('antes', '09:00:00', ['gps_em' => '2026-10-30T09:00:00-03:00'])->assertUnprocessable()->assertJsonValidationErrors('gps_em');
        $this->foto('antes', '14:00:00')->assertUnprocessable()->assertJsonValidationErrors('gps_em'); // hora no futuro

        $v = $this->visitaAtual();
        $this->assertSame('previsto', $v->status->value);
        $this->assertNull($v->check_in);
        $this->tenant->run(fn () => $this->assertSame(0, Visita::find($this->visita->id)->fotos()->count()));
    }

    public function test_depois_e_ruptura_exigem_a_foto_antes_primeiro(): void
    {
        foreach (['depois', 'ruptura'] as $tipo) {
            $this->foto($tipo)->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'foto de antes da gôndola'));
        }
        $this->assertNull($this->visitaAtual()->check_in);

        $this->foto('antes', '09:00:00')->assertCreated();
        $this->foto('depois', '09:20:00')->assertCreated()->assertJsonPath('check_in.origem', 'foto');
        $this->foto('ruptura', '09:25:00')->assertCreated();
    }

    public function test_fluxo_completo_sem_o_endpoint_de_check_in(): void
    {
        $this->foto('antes', '09:05:00')->assertCreated();
        $this->foto('depois', '09:20:00')->assertCreated();
        $this->postJson($this->url('concluir'), ['uuid' => (string) Str::uuid(), 'tipo' => 'normal', 'finalizada_em' => '2026-10-31T09:30:00-03:00', 'observacao' => '', 'itens' => [['sku_id' => $this->sku->id, 'estoque' => 'tem_estoque']]])->assertCreated();

        $v = $this->visitaAtual();
        $this->assertSame('realizada', $v->status->value);
        // Duração conta da foto antes (09:05) até a conclusão (09:30).
        $this->assertSame(25 * 60, $v->duracao_segundos);
    }

    public function test_impedimento_de_acesso_usa_a_foto_da_entrada_como_check_in(): void
    {
        $this->foto('impedimento', '09:00:00')->assertCreated()->assertJsonPath('status', 'em_andamento');
        $this->postJson($this->url('concluir'), ['uuid' => (string) Str::uuid(), 'tipo' => 'impedimento', 'finalizada_em' => '2026-10-31T09:10:00-03:00', 'observacao' => 'Gerente não liberou o acesso ao estoque.'])->assertCreated();

        $this->assertSame('em_atencao', $this->visitaAtual()->status->value);
    }

    public function test_sincronizacao_tardia_pela_foto_reabre_visita_nao_executada_e_avisa(): void
    {
        $this->criarUsuario($this->tenant, Perfil::Supervisor, ['agencia_id' => $this->agencia->id]);
        $atrasada = $this->nova('nao_executada', '2026-10-30');

        $this->foto('antes', '09:00:00', ['gps_em' => '2026-10-30T09:00:00-03:00', 'aparelho_em' => '2026-10-30T09:00:00-03:00'], $atrasada)
            ->assertCreated()->assertJsonPath('status', 'em_andamento');
        $this->assertSame('em_andamento', $this->visitaAtual($atrasada)->status->value);
        $this->tenant->run(fn () => $this->assertTrue(Alerta::where('tipo', 'sincronizacao_tardia')->exists()));
    }

    public function test_visita_ja_encerrada_nao_reabre_pela_foto_e_o_endpoint_antigo_continua(): void
    {
        $this->postJson($this->url('check-in'), $this->gps())->assertCreated(); // APK antigo
        $this->foto('antes', '09:05:00')->assertCreated()->assertJsonPath('check_in.lat', -29.168);
        $this->assertArrayNotHasKey('origem', $this->visitaAtual()->check_in, 'o check-in do APK antigo é preservado');

        $encerrada = $this->nova('realizada', '2026-10-31');
        $this->tenant->run(fn () => Visita::whereKey($encerrada->id)->update(['roteiro_id' => Roteiro::first()->id]));
        $this->foto('antes', '09:05:00', [], $encerrada)->assertStatus(409);
    }

    public function test_outro_promotor_nao_abre_a_visita(): void
    {
        $outro = $this->criarUsuario($this->tenant, Perfil::Promotor, ['email' => 'outro@xyz.test', 'agencia_id' => $this->agencia->id, 'tipo_vinculo' => 'clt']);
        Sanctum::actingAs($outro, ['promotor']);

        $this->foto('antes')->assertNotFound();
        $this->assertNull($this->visitaAtual()->check_in);
    }
}
