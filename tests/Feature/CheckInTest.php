<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\Industria;
use App\Models\Pdv;
use App\Models\Roteiro;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visita;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckInTest extends TestCase
{
    private Tenant $tenant;

    private User $promotor;

    private Visita $visita;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');
        $this->tenant = $this->criarCliente('xyz', 'agencia');
        $agencia = $this->tenant->run(fn () => Agencia::first()->id);
        $this->promotor = $this->criarUsuario($this->tenant, Perfil::Promotor, ['agencia_id' => $agencia, 'tipo_vinculo' => 'clt']);
        $this->visita = $this->tenant->run(function () use ($agencia) {
            $pdv = Pdv::create(['cnpj' => self::cnpj(1), 'nome' => 'Mercado', 'cidade' => 'Caxias do Sul', 'uf' => 'RS', 'lat' => -29.168, 'lng' => -51.179, 'raio_m' => 200]);
            $marca = Industria::create(['nome' => 'Marca']);
            $roteiro = Roteiro::create(['promotor_id' => $this->promotor->id, 'data' => '2026-10-31']);

            return Visita::create(['roteiro_id' => $roteiro->id, 'agencia_id' => $agencia, 'pdv_id' => $pdv->id, 'industria_id' => $marca->id, 'data' => '2026-10-31', 'origem' => 'demanda']);
        });
        Sanctum::actingAs($this->promotor, ['promotor']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function url(): string
    {
        return 'http://xyz.test/api/v1/visitas/'.$this->visita->id.'/check-in';
    }

    private function dados(array $extra = []): array
    {
        return array_merge(['lat' => -29.168, 'lng' => -51.179, 'gps_em' => now()->toIso8601String(), 'aparelho_em' => now()->toIso8601String(), 'gps_simulado' => false], $extra);
    }

    public function test_registra_check_in_e_nao_sobrescreve_no_reenvio(): void
    {
        $primeiro = $this->postJson($this->url(), $this->dados())->assertCreated()
            ->assertJsonPath('status', 'em_andamento')->json('check_in');
        $this->tenant->run(fn () => $this->assertFalse($this->visita->fresh()->podeSerMovida()));
        $this->postJson($this->url(), $this->dados(['lat' => -29.1681]))->assertOk()->assertJsonPath('check_in', $primeiro);
    }

    public function test_recusa_fora_do_raio_sem_alterar_visita(): void
    {
        $this->postJson($this->url(), $this->dados(['lat' => -29.178]))->assertUnprocessable()->assertJsonValidationErrors('lat');
        $this->tenant->run(function () {
            $this->assertNull($this->visita->fresh()->check_in);
            $this->assertSame('previsto', $this->visita->fresh()->status->value);
        });
    }

    public function test_aceita_distancia_dentro_do_raio(): void
    {
        $this->postJson($this->url(), $this->dados(['lat' => -29.169]))->assertCreated();
    }

    public function test_respeita_raio_especifico_do_pdv(): void
    {
        $this->tenant->run(fn () => $this->visita->pdv->update(['raio_m' => 150]));
        $this->postJson($this->url(), $this->dados(['lat' => -29.1696]))->assertUnprocessable();
    }

    public function test_recusa_gps_simulado_coordenadas_invalidas_e_hora_sem_fuso(): void
    {
        foreach ([['gps_simulado' => true], ['lat' => 91], ['lng' => -181], ['gps_em' => '2026-10-31 12:00:00']] as $extra) {
            $this->postJson($this->url(), $this->dados($extra))->assertUnprocessable();
        }
    }

    public function test_recusa_gps_de_outro_dia_ou_futuro(): void
    {
        foreach ([now()->subDay(), now()->addHour()] as $hora) {
            $this->postJson($this->url(), $this->dados(['gps_em' => $hora->toIso8601String()]))->assertUnprocessable()->assertJsonValidationErrors('gps_em');
        }
    }

    public function test_aceita_sincronizacao_no_dia_seguinte_e_guarda_ambos_horarios(): void
    {
        $dados = $this->dados();
        Carbon::setTestNow(now()->addDay());
        $this->postJson($this->url(), $dados)->assertCreated()
            ->assertJsonPath('check_in.gps_em', $dados['gps_em'])
            ->assertJsonPath('check_in.recebido_em', now()->toIso8601String());
    }

    public function test_check_in_a_noite_usa_o_dia_local_e_nao_o_dia_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01T02:00:00+00:00'));
        $this->postJson($this->url(), $this->dados([
            'gps_em' => '2026-11-01T01:30:00+00:00',
            'aparelho_em' => '2026-10-31T22:30:00-03:00',
        ]))->assertCreated()->assertJsonPath('check_in.gps_em', '2026-10-31T22:30:00-03:00');
    }

    public function test_outro_promotor_e_perfil_de_painel_nao_podem_executar(): void
    {
        $outro = $this->criarUsuario($this->tenant, Perfil::Promotor, ['email' => 'outro@xyz.test', 'agencia_id' => $this->promotor->agencia_id]);
        Sanctum::actingAs($outro, ['promotor']);
        $this->postJson($this->url(), $this->dados())->assertNotFound();
        $admin = $this->tenant->run(fn () => User::where('perfil', 'admin_instalacao')->first());
        Sanctum::actingAs($admin);
        $this->postJson($this->url(), $this->dados())->assertForbidden();
    }

    public function test_recusa_pdv_sem_coordenadas_e_visita_encerrada(): void
    {
        $this->tenant->run(fn () => $this->visita->pdv->update(['lat' => null]));
        $this->postJson($this->url(), $this->dados())->assertUnprocessable()->assertJsonValidationErrors('pdv');
        $this->tenant->run(fn () => $this->visita->update(['status' => 'realizada']));
        $this->postJson($this->url(), $this->dados())->assertConflict();
    }
}
