<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Enums\StatusVisita;
use App\Models\Agencia;
use App\Models\ContratoVisita;
use App\Models\Industria;
use App\Models\Pdv;
use App\Models\Roteiro;
use App\Models\Sku;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visita;
use App\Services\Roteiros\GeradorVisitas;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoteiroTest extends TestCase
{
    private const URL = 'http://xyz.test';

    private Tenant $tenant;

    private Agencia $agencia;

    private Industria $marca;

    private Pdv $centro;

    private Pdv $bairro;

    private User $supervisor;

    private User $joao;

    private User $maria;

    protected function setUp(): void
    {
        parent::setUp();

        // Quarta-feira. Semana: segunda 12/10 a domingo 18/10.
        Carbon::setTestNow('2026-10-14 10:00:00');

        $this->tenant = $this->criarCliente('xyz', 'agencia', 'xyz.test');
        [$this->agencia, $this->marca, $this->centro, $this->bairro] = $this->tenant->run(fn () => [
            Agencia::first(),
            Industria::create(['nome' => 'Marca A']),
            Pdv::create(['cnpj' => self::cnpj(1), 'nome' => 'Super Centro', 'cidade' => 'Caxias do Sul', 'uf' => 'RS', 'lat' => -29.168, 'lng' => -51.179, 'raio_m' => 180]),
            Pdv::create(['cnpj' => self::cnpj(2), 'nome' => 'Mercado Bairro', 'cidade' => 'Caxias do Sul', 'uf' => 'RS']),
        ]);

        $this->supervisor = $this->criarUsuario($this->tenant, Perfil::Supervisor, ['agencia_id' => $this->agencia->id]);
        $this->joao = $this->criarUsuario($this->tenant, Perfil::Promotor, ['nome' => 'João', 'email' => 'joao@xyz.test', 'agencia_id' => $this->agencia->id, 'tipo_vinculo' => 'clt']);
        $this->maria = $this->criarUsuario($this->tenant, Perfil::Promotor, ['nome' => 'Maria', 'email' => 'maria@xyz.test', 'agencia_id' => $this->agencia->id, 'tipo_vinculo' => 'freelancer']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function contrato(array $extra = []): ContratoVisita
    {
        return $this->tenant->run(fn () => ContratoVisita::create(array_merge([
            'pdv_id' => $this->centro->id,
            'industria_id' => $this->marca->id,
            'agencia_id' => $this->agencia->id,
            'frequencia_semana' => 3,
            'dias' => [1, 3, 5],
            'inicio' => '2026-10-01',
        ], $extra)));
    }

    private function gerar(string $inicio = '2026-10-12', string $fim = '2026-10-25'): array
    {
        return $this->tenant->run(fn () => app(GeradorVisitas::class)->gerar(Carbon::parse($inicio), Carbon::parse($fim)));
    }

    /** @return list<string> */
    private function datasDasVisitas(): array
    {
        return $this->tenant->run(fn () => Visita::orderBy('data')->get()->map(fn ($v) => $v->data->toDateString())->all());
    }

    private function visitaEm(string $data, ?Pdv $pdv = null): Visita
    {
        return $this->tenant->run(fn () => Visita::whereDate('data', $data)->where('pdv_id', ($pdv ?? $this->centro)->id)->sole());
    }

    public function test_gera_visitas_previstas_dos_contratos_a_partir_de_hoje_sem_duplicar(): void
    {
        $this->contrato();
        $this->contrato(['pdv_id' => $this->bairro->id, 'frequencia_semana' => 1, 'dias' => [6]]);

        $resultado = $this->gerar();

        // Segunda 12 já passou; quarta 14 (hoje) em diante: 5 do contrato seg/qua/sex + 2 sábados.
        $this->assertSame(['criadas' => 7, 'removidas' => 0, 'atribuidas' => 0], $resultado);
        $this->assertSame(
            ['2026-10-14', '2026-10-16', '2026-10-17', '2026-10-19', '2026-10-21', '2026-10-23', '2026-10-24'],
            array_values(array_unique($this->datasDasVisitas()))
        );

        $this->tenant->run(function () {
            $visita = Visita::whereDate('data', '2026-10-14')->sole();
            $this->assertSame(StatusVisita::Previsto, $visita->status);
            $this->assertSame('contrato', $visita->origem->value);
            $this->assertNull($visita->roteiro_id);
            $this->assertSame($this->agencia->id, $visita->agencia_id);
            $this->assertSame($this->marca->id, $visita->industria_id);
        });

        // Rodar de novo não duplica.
        $this->assertSame(['criadas' => 0, 'removidas' => 0, 'atribuidas' => 0], $this->gerar());
        $this->tenant->run(fn () => $this->assertSame(7, Visita::count()));
    }

    public function test_regeracao_remove_visitas_que_o_contrato_deixou_de_prever(): void
    {
        $contrato = $this->contrato();
        $this->gerar();

        // Uma visita de hoje já começou: não pode sumir.
        $this->tenant->run(fn () => Visita::whereDate('data', '2026-10-14')->update(['status' => StatusVisita::EmAndamento->value]));

        // Contrato passa a ser só às segundas e termina em 20/10.
        $this->tenant->run(fn () => $contrato->update(['frequencia_semana' => 1, 'dias' => [1], 'fim' => '2026-10-20']));

        $resultado = $this->gerar();

        // Saem 16, 21 e 23; fica a segunda 19; a de hoje (em andamento) não é tocada.
        $this->assertSame(3, $resultado['removidas']);
        $this->assertSame(['2026-10-14', '2026-10-19'], $this->datasDasVisitas());

        // PDV inativado: as previstas somem.
        $this->tenant->run(fn () => $this->centro->update(['ativo' => false]));
        $this->gerar();
        $this->assertSame(['2026-10-14'], $this->datasDasVisitas());
    }

    public function test_comando_gera_para_os_clientes_ativos(): void
    {
        $this->contrato();

        $this->artisan('roteiros:gerar')
            ->expectsOutputToContain('xyz: 5 criada(s)')
            ->assertSuccessful();

        $this->assertSame(
            ['2026-10-14', '2026-10-16', '2026-10-19', '2026-10-21', '2026-10-23'],
            $this->datasDasVisitas()
        );
    }

    public function test_visita_nova_repete_o_promotor_da_semana_anterior(): void
    {
        $this->contrato();
        $this->gerar('2026-10-12', '2026-10-18');

        $this->actingAs($this->supervisor)
            ->putJson(self::URL.'/visitas/'.$this->visitaEm('2026-10-14')->id.'/mover', ['promotor_id' => $this->joao->id, 'data' => '2026-10-14'])
            ->assertOk();

        // Maria fica com a sexta, mas é desativada antes da geração.
        $this->actingAs($this->supervisor)
            ->putJson(self::URL.'/visitas/'.$this->visitaEm('2026-10-16')->id.'/mover', ['promotor_id' => $this->maria->id, 'data' => '2026-10-16'])
            ->assertOk();
        $this->tenant->run(fn () => User::whereKey($this->maria->id)->update(['ativo' => false]));

        $resultado = $this->gerar('2026-10-19', '2026-10-25');
        $this->assertSame(['criadas' => 3, 'removidas' => 0, 'atribuidas' => 1], $resultado);

        $this->tenant->run(function () {
            $quarta = Visita::with('roteiro')->whereDate('data', '2026-10-21')->sole();
            $this->assertSame($this->joao->id, $quarta->roteiro->promotor_id);
            $this->assertSame($this->supervisor->id, $quarta->roteiro->supervisor_id);

            $this->assertNull(Visita::whereDate('data', '2026-10-23')->sole()->roteiro_id, 'Promotor inativo não recebe visita.');
            $this->assertNull(Visita::whereDate('data', '2026-10-19')->sole()->roteiro_id);
        });
    }

    public function test_supervisor_atribui_reordena_e_desatribui(): void
    {
        $this->contrato();
        $this->contrato(['pdv_id' => $this->bairro->id, 'frequencia_semana' => 1, 'dias' => [3]]);
        $this->gerar('2026-10-12', '2026-10-18');

        $centro = $this->visitaEm('2026-10-14');
        $bairro = $this->visitaEm('2026-10-14', $this->bairro);
        $this->actingAs($this->supervisor);

        $this->putJson(self::URL."/visitas/{$centro->id}/mover", ['promotor_id' => $this->joao->id, 'data' => '2026-10-14'])->assertOk();
        $this->putJson(self::URL."/visitas/{$bairro->id}/mover", ['promotor_id' => $this->joao->id, 'data' => '2026-10-14'])->assertOk();

        $this->tenant->run(function () use ($centro, $bairro) {
            $roteiro = Roteiro::sole();
            $this->assertSame($this->joao->id, $roteiro->promotor_id);
            $this->assertSame('2026-10-14', $roteiro->data->toDateString());
            $this->assertSame($this->supervisor->id, $roteiro->supervisor_id);
            $this->assertSame([$centro->id, $bairro->id], $roteiro->visitas->pluck('id')->all(), 'A nova entra no fim.');
        });

        // Arrasta o bairro para o topo.
        $this->putJson(self::URL."/visitas/{$bairro->id}/mover", [
            'promotor_id' => $this->joao->id, 'data' => '2026-10-14', 'ordem' => [$bairro->id, $centro->id],
        ])->assertOk();
        $this->tenant->run(fn () => $this->assertSame([$bairro->id, $centro->id], Roteiro::sole()->visitas->pluck('id')->all()));

        // Ordem com visita de outro dia é recusada.
        $sexta = $this->visitaEm('2026-10-16');
        $this->putJson(self::URL."/visitas/{$bairro->id}/mover", [
            'promotor_id' => $this->joao->id, 'data' => '2026-10-14', 'ordem' => [$bairro->id, $sexta->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('ordem');

        // Move o centro para a Maria na sexta e o bairro de volta para "sem promotor": o roteiro do João some.
        $this->putJson(self::URL."/visitas/{$centro->id}/mover", ['promotor_id' => $this->maria->id, 'data' => '2026-10-16'])->assertOk();
        $this->putJson(self::URL."/visitas/{$bairro->id}/mover", ['promotor_id' => null, 'data' => '2026-10-14'])->assertOk();

        // Regerar não recria a quarta nem apaga a visita que foi movida para sexta.
        $this->assertSame(['criadas' => 0, 'removidas' => 0, 'atribuidas' => 0], $this->gerar('2026-10-12', '2026-10-18'));

        $this->tenant->run(function () use ($centro, $bairro) {
            $this->assertSame('2026-10-14', $centro->fresh()->data_prevista->toDateString());
            $this->assertSame(2, Visita::where('pdv_id', $this->centro->id)->whereDate('data', '2026-10-16')->count(), 'A movida e a de sexta do contrato.');
            $this->assertSame(['2026-10-16'], Roteiro::pluck('data')->map(fn ($d) => $d->toDateString())->all());
            $this->assertSame($this->maria->id, $centro->fresh()->roteiro->promotor_id);
            $this->assertSame('2026-10-16', $centro->fresh()->data->toDateString());
            $this->assertNull($bairro->fresh()->roteiro_id);
        });

        // Formulário (sem JavaScript): volta para a tela com mensagem.
        $this->from(self::URL.'/roteiros')
            ->put(self::URL."/visitas/{$bairro->id}/mover", ['promotor_id' => $this->maria->id, 'data' => '2026-10-15'])
            ->assertRedirect(self::URL.'/roteiros')
            ->assertSessionHas('status', 'Visita a Mercado Bairro atribuída a Maria em 15/10.');
    }

    public function test_regras_para_mover_visitas(): void
    {
        $this->contrato();
        $this->gerar('2026-10-12', '2026-10-18');
        $quarta = $this->visitaEm('2026-10-14');
        $this->actingAs($this->supervisor);

        $this->putJson(self::URL."/visitas/{$quarta->id}/mover", ['promotor_id' => $this->joao->id, 'data' => '2026-10-13'])
            ->assertUnprocessable()->assertJsonValidationErrors(['data' => 'Não dá para mover uma visita para um dia que já passou.']);

        $outraAgencia = $this->tenant->run(fn () => Agencia::create(['nome' => 'Outra']));
        $promotorDeFora = $this->criarUsuario($this->tenant, Perfil::Promotor, ['email' => 'fora@xyz.test', 'agencia_id' => $outraAgencia->id, 'tipo_vinculo' => 'clt']);
        $this->putJson(self::URL."/visitas/{$quarta->id}/mover", ['promotor_id' => $promotorDeFora->id, 'data' => '2026-10-14'])
            ->assertUnprocessable()->assertJsonValidationErrors('promotor_id');
        $this->putJson(self::URL."/visitas/{$quarta->id}/mover", ['promotor_id' => $this->supervisor->id, 'data' => '2026-10-14'])
            ->assertUnprocessable()->assertJsonValidationErrors('promotor_id');

        $this->tenant->run(fn () => $quarta->update(['status' => StatusVisita::Realizada->value]));
        $this->putJson(self::URL."/visitas/{$quarta->id}/mover", ['promotor_id' => $this->joao->id, 'data' => '2026-10-15'])
            ->assertUnprocessable()->assertJsonValidationErrors('visita');

        // Supervisor de outra agência não mexe.
        $supervisorDeFora = $this->criarUsuario($this->tenant, Perfil::Supervisor, ['email' => 'sup2@xyz.test', 'agencia_id' => $outraAgencia->id]);
        $sexta = $this->visitaEm('2026-10-16');
        $this->actingAs($supervisorDeFora)
            ->putJson(self::URL."/visitas/{$sexta->id}/mover", ['promotor_id' => null, 'data' => '2026-10-16'])
            ->assertForbidden();
    }

    public function test_industria_ve_os_roteiros_mas_nao_atribui(): void
    {
        $this->contrato();
        $this->gerar('2026-10-12', '2026-10-18');
        $quarta = $this->visitaEm('2026-10-14');
        $this->actingAs($this->supervisor)->putJson(self::URL."/visitas/{$quarta->id}/mover", ['promotor_id' => $this->joao->id, 'data' => '2026-10-14']);

        $outraMarca = $this->tenant->run(fn () => Industria::create(['nome' => 'Marca B']));

        foreach ([Perfil::GerenteTrade, Perfil::Representante] as $perfil) {
            $usuario = $this->criarUsuario($this->tenant, $perfil, ['industria_id' => $this->marca->id]);
            $this->actingAs($usuario)->get(self::URL.'/roteiros')
                ->assertOk()
                ->assertSee('Super Centro')
                ->assertSee('João')
                ->assertSee('A atribuição de visitas aos promotores é feita pela agência.')
                ->assertDontSee('Gerar visitas dos contratos')
                ->assertDontSee('data-movel', false);
            $this->actingAs($usuario)->putJson(self::URL."/visitas/{$quarta->id}/mover", ['promotor_id' => $this->maria->id, 'data' => '2026-10-14'])
                ->assertForbidden();
            $this->actingAs($usuario)->post(self::URL.'/roteiros/gerar')->assertForbidden();
        }

        // Outra indústria não vê as visitas da Marca A.
        $gerenteB = $this->criarUsuario($this->tenant, Perfil::GerenteTrade, ['email' => 'b@xyz.test', 'industria_id' => $outraMarca->id]);
        $this->actingAs($gerenteB)->get(self::URL.'/roteiros')->assertOk()->assertDontSee('Super Centro');

        // Admin da instalação acompanha, mas também não atribui.
        $admin = $this->tenant->run(fn () => User::where('perfil', 'admin_instalacao')->first());
        $this->actingAs($admin)->get(self::URL.'/roteiros')->assertOk()->assertSee('Super Centro')->assertDontSee('data-movel', false);
        $this->actingAs($admin)->putJson(self::URL."/visitas/{$quarta->id}/mover", ['promotor_id' => null, 'data' => '2026-10-14'])->assertForbidden();

        // Promotor não usa o painel.
        $this->actingAs($this->joao)->get(self::URL.'/roteiros')->assertForbidden();

        $this->tenant->run(fn () => $this->assertSame($this->joao->id, $quarta->fresh()->roteiro->promotor_id));
    }

    public function test_tela_da_semana_e_mapa_do_dia(): void
    {
        $this->contrato();
        $this->actingAs($this->supervisor);

        $this->post(self::URL.'/roteiros/gerar', ['semana' => '2026-10-12'])
            ->assertRedirect(self::URL.'/roteiros?semana=2026-10-12')
            ->assertSessionHas('status', 'Visitas da semana atualizadas: 2 nova(s), 0 já com o promotor da semana anterior, 0 removida(s).');

        $quarta = $this->visitaEm('2026-10-14');
        $this->putJson(self::URL."/visitas/{$quarta->id}/mover", ['promotor_id' => $this->joao->id, 'data' => '2026-10-14'])->assertOk();

        $this->get(self::URL.'/roteiros?semana=2026-10-12')
            ->assertOk()
            ->assertSee('Roteiros — 12/10 a 18/10/2026')
            ->assertSee(['Sem promotor', 'João', 'Maria', 'Super Centro'])
            ->assertSee('data-movel', false)
            ->assertSee('Gerar visitas dos contratos');

        $this->get(self::URL.'/roteiros?semana=2026-10-12&dia=2026-10-14&promotor='.$this->joao->id)
            ->assertOk()
            ->assertSee('id="mapa-dia"', false)
            ->assertSee('"lat":-29.168', false);

        $this->get(self::URL.'/roteiros?semana=2026-10-12&dia=2026-10-15&promotor='.$this->joao->id)
            ->assertSee('Nenhuma visita com coordenadas neste dia.');

        // Semana passada: sem botão de gerar.
        $this->get(self::URL.'/roteiros?semana=2026-10-05')->assertOk()->assertDontSee('Gerar visitas dos contratos');
    }

    public function test_api_entrega_o_roteiro_do_dia_ao_promotor(): void
    {
        Storage::fake('local');
        $this->contrato();
        $this->contrato(['pdv_id' => $this->bairro->id, 'frequencia_semana' => 1, 'dias' => [3]]);
        $this->gerar('2026-10-12', '2026-10-18');

        [$sku, $skuDeOutraMarca] = $this->tenant->run(function () {
            $sku = Sku::create(['industria_id' => $this->marca->id, 'codigo' => 'ALG-25', 'descricao' => 'Alumgrill 30 cm', 'foto' => UploadedFile::fake()->image('a.jpg')->store('skus', 'local')]);
            $sku->embalagens()->createMany([
                ['tipo' => 'caixa_master', 'quantidade' => 25, 'gtin' => '17891000315504'],
                ['tipo' => 'unidade', 'quantidade' => 1, 'gtin' => '7891000315507'],
            ]);
            Sku::create(['industria_id' => $this->marca->id, 'codigo' => 'OLD', 'descricao' => 'Fora de linha', 'ativo' => false]);
            $outra = Industria::create(['nome' => 'Marca B']);

            return [$sku, Sku::create(['industria_id' => $outra->id, 'codigo' => 'B1', 'descricao' => 'Outro', 'foto' => UploadedFile::fake()->image('b.jpg')->store('skus', 'local')])];
        });

        $centro = $this->visitaEm('2026-10-14');
        $bairro = $this->visitaEm('2026-10-14', $this->bairro);
        $this->actingAs($this->supervisor);
        $this->putJson(self::URL."/visitas/{$centro->id}/mover", ['promotor_id' => $this->joao->id, 'data' => '2026-10-14']);
        $this->putJson(self::URL."/visitas/{$bairro->id}/mover", ['promotor_id' => $this->joao->id, 'data' => '2026-10-14', 'ordem' => [$bairro->id, $centro->id]]);
        $this->app['auth']->forgetGuards();

        Sanctum::actingAs($this->joao, ['promotor']);

        $resposta = $this->getJson(self::URL.'/api/v1/roteiro')->assertOk();
        $resposta->assertJsonPath('data', '2026-10-14')
            ->assertJsonPath('supervisor.id', $this->supervisor->id)
            ->assertJsonCount(2, 'visitas')
            ->assertJsonPath('visitas.0.id', $bairro->id)
            ->assertJsonPath('visitas.0.ordem', 1)
            ->assertJsonPath('visitas.1.pdv.nome', 'Super Centro')
            ->assertJsonPath('visitas.1.pdv.lat', -29.168)
            ->assertJsonPath('visitas.1.pdv.raio_m', 180)
            ->assertJsonPath('visitas.1.status', 'previsto')
            ->assertJsonCount(1, 'industrias')
            ->assertJsonPath('industrias.0.nome', 'Marca A')
            ->assertJsonCount(1, 'industrias.0.skus')
            ->assertJsonPath('industrias.0.skus.0.codigo', 'ALG-25')
            ->assertJsonPath('industrias.0.skus.0.embalagens.0.tipo', 'unidade')
            ->assertJsonPath('industrias.0.skus.0.embalagens.1.gtin', '17891000315504');

        $this->get($resposta->json('industrias.0.skus.0.foto_url'))->assertOk();
        $this->get(self::URL."/api/v1/skus/{$skuDeOutraMarca->id}/foto")->assertNotFound();

        // Dia sem roteiro: lista vazia. Data inválida: 422.
        $this->getJson(self::URL.'/api/v1/roteiro?data=2026-10-16')->assertOk()->assertJsonCount(0, 'visitas')->assertJsonPath('roteiro_id', null);
        $this->getJson(self::URL.'/api/v1/roteiro?data=16/10/2026')->assertUnprocessable();

        // A Maria não vê o roteiro do João.
        Sanctum::actingAs($this->maria, ['promotor']);
        $this->getJson(self::URL.'/api/v1/roteiro')->assertOk()->assertJsonCount(0, 'visitas');
        $this->get(self::URL."/api/v1/skus/{$sku->id}/foto")->assertNotFound();

        // Perfil de painel não usa a API do roteiro.
        Sanctum::actingAs($this->supervisor);
        $this->getJson(self::URL.'/api/v1/roteiro')->assertForbidden();
    }
}
