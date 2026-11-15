<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\FotoAvaliacao;
use App\Models\Industria;
use App\Models\Pdv;
use App\Models\Roteiro;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visita;
use App\Models\VisitaFoto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Fase 10 (10.7): avaliação (1 a 5) e tags das fotos, com filtro na galeria. */
class AvaliacaoDeFotosTest extends TestCase
{
    private const URL = 'http://xyz.test';

    private Tenant $tenant;

    private Visita $visita;

    private VisitaFoto $foto;

    private User $admin;

    private User $supervisor;

    private User $promotor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');
        $this->tenant = $this->criarCliente('xyz', 'agencia');
        $agencia = $this->tenant->run(fn () => Agencia::first());
        $this->promotor = $this->criarUsuario($this->tenant, Perfil::Promotor, ['agencia_id' => $agencia->id, 'tipo_vinculo' => 'clt']);
        $this->supervisor = $this->criarUsuario($this->tenant, Perfil::Supervisor, ['agencia_id' => $agencia->id, 'email' => 'sup@xyz.test']);
        $this->admin = $this->tenant->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
        [$this->visita, $this->foto] = $this->tenant->run(function () use ($agencia) {
            $marca = Industria::create(['nome' => 'Marca Aurora']);
            $pdv = Pdv::create(['cnpj' => self::cnpj(1), 'nome' => 'Loja Centro']);
            $r = Roteiro::create(['promotor_id' => $this->promotor->id, 'agencia_id' => $agencia->id, 'data' => '2026-10-31']);
            $v = Visita::create(['roteiro_id' => $r->id, 'data' => '2026-10-31', 'pdv_id' => $pdv->id, 'industria_id' => $marca->id, 'agencia_id' => $agencia->id, 'origem' => 'demanda', 'status' => 'realizada']);
            $f = VisitaFoto::create(['visita_id' => $v->id, 'uuid' => (string) Str::uuid(), 'tipo' => 'antes', 'caminho' => 'x.jpg', 'hash_origem' => Str::random(64), 'metadados' => ['gps_em' => '2026-10-31T10:00:00-03:00', 'lat' => 0, 'lng' => 0, 'distancia_m' => 5]]);

            return [$v, $f];
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function url(): string
    {
        return self::URL."/visitas/{$this->visita->id}/fotos/{$this->foto->id}/avaliacao";
    }

    public function test_gestao_avalia_com_nota_e_tags_e_refazer_substitui(): void
    {
        $this->actingAs($this->admin)->get(self::URL."/visitas/{$this->visita->id}")->assertOk()->assertSee('Avaliar esta foto');
        $this->post($this->url(), ['nota' => 4, 'tags' => ['gondola_ok', 'material_pdv']])->assertRedirect();
        $this->tenant->run(function () {
            $a = FotoAvaliacao::sole();
            $this->assertSame(4, $a->nota);
            $this->assertSame(['gondola_ok', 'material_pdv'], $a->tags);
            $this->assertSame($this->admin->nome, $a->usuario_nome);
        });
        $this->get(self::URL."/visitas/{$this->visita->id}")->assertSee('Nota 4,0 de 5')->assertSee('Gôndola ok, Material de PDV');

        // Refazer substitui; sem nota nem tags remove.
        $this->post($this->url(), ['nota' => 2, 'tags' => ['refazer']]);
        $this->assertSame(2, $this->tenant->run(fn () => FotoAvaliacao::sole()->nota));
        $this->post($this->url(), []);
        $this->assertSame(0, $this->tenant->run(fn () => FotoAvaliacao::count()));
    }

    public function test_varios_avaliadores_somam_na_media(): void
    {
        $this->actingAs($this->admin)->post($this->url(), ['nota' => 5]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->supervisor)->post($this->url(), ['nota' => 3, 'tags' => ['foto_ruim']]);

        $this->tenant->run(function () {
            $this->assertSame(2, FotoAvaliacao::count());
            $this->assertSame(4.0, VisitaFoto::with('avaliacoes')->find($this->foto->id)->notaMedia());
        });
        $this->get(self::URL."/visitas/{$this->visita->id}")->assertSee('Nota 4,0 de 5')->assertSee('2 avaliação(ões)');
    }

    public function test_valida_e_so_a_gestao_avalia(): void
    {
        $this->actingAs($this->admin);
        $this->post($this->url(), ['nota' => 9])->assertSessionHasErrors('nota');
        $this->post($this->url(), ['nota' => 3, 'tags' => ['inventada']])->assertSessionHasErrors('tags.0');
        $this->post(self::URL."/visitas/{$this->visita->id}/fotos/9999/avaliacao", ['nota' => 3])->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->promotor)->post($this->url(), ['nota' => 5])->assertForbidden();
        $this->assertSame(0, $this->tenant->run(fn () => FotoAvaliacao::count()));
    }

    public function test_galeria_filtra_por_nota_e_tag(): void
    {
        $this->actingAs($this->admin)->post($this->url(), ['nota' => 2, 'tags' => ['refazer']]);

        $url = self::URL.'/indicadores/galeria?inicio=2026-10-01&fim=2026-10-31';
        $this->get($url)->assertOk()->assertSee('Loja Centro')->assertSee('Filtrar por avaliação');
        $this->get($url.'&nota_max=2')->assertOk()->assertSee('Detalhes da visita');
        $this->get($url.'&nota_min=4')->assertOk()->assertDontSee('Detalhes da visita');
        $this->get($url.'&tag=refazer')->assertOk()->assertSee('Detalhes da visita');
        $this->get($url.'&tag=gondola_ok')->assertOk()->assertDontSee('Detalhes da visita');
        $this->get($url.'&tag=inventada')->assertSessionHasErrors('tag');
    }
}
