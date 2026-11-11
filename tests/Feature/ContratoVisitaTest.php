<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\ContratoVisita;
use App\Models\Industria;
use App\Models\Pdv;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ContratoVisitaTest extends TestCase
{
    private const URL = 'http://xyz.test';

    private Tenant $tenant;

    private Agencia $agencia;

    private Industria $marcaA;

    private Industria $marcaB;

    private Pdv $pdv;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-14 10:00:00'); // quarta-feira

        // Instalação de agência atendendo duas indústrias.
        $this->tenant = $this->criarCliente('xyz', 'agencia', 'xyz.test');
        [$this->agencia, $this->marcaA, $this->marcaB, $this->pdv] = $this->tenant->run(fn () => [
            Agencia::first(),
            Industria::create(['nome' => 'Marca A']),
            Industria::create(['nome' => 'Marca B']),
            Pdv::create(['cnpj' => self::cnpj(1), 'nome' => 'Supermercado Central', 'cidade' => 'Caxias do Sul', 'uf' => 'RS']),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function adminAgencia(): User
    {
        return $this->criarUsuario($this->tenant, Perfil::AdminAgencia, ['agencia_id' => $this->agencia->id]);
    }

    private function dados(array $extra = []): array
    {
        return array_merge([
            'pdv_id' => $this->pdv->id,
            'industria_id' => $this->marcaA->id,
            'frequencia_semana' => 2,
            'dias' => [4, 1],
            'inicio' => '2026-10-01',
            'fim' => '',
            'ativo' => '1',
        ], $extra);
    }

    private function contrato(array $extra = []): ContratoVisita
    {
        return $this->tenant->run(fn () => ContratoVisita::create(array_merge([
            'pdv_id' => $this->pdv->id,
            'industria_id' => $this->marcaA->id,
            'agencia_id' => $this->agencia->id,
            'frequencia_semana' => 1,
            'dias' => [2],
            'inicio' => '2026-01-01',
        ], $extra)));
    }

    public function test_admin_da_agencia_cadastra_contrato_da_propria_agencia(): void
    {
        $outraAgencia = $this->tenant->run(fn () => Agencia::create(['nome' => 'Outra agência']));
        $this->actingAs($this->adminAgencia());

        $this->get(self::URL.'/contratos/create?pdv_id='.$this->pdv->id)->assertOk()->assertSee('Supermercado Central');

        // Tenta registrar para outra agência: o sistema força a própria.
        $this->post(self::URL.'/contratos', $this->dados(['agencia_id' => $outraAgencia->id]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(self::URL.'/contratos');

        $this->tenant->run(function () {
            $contrato = ContratoVisita::sole();
            $this->assertSame($this->agencia->id, $contrato->agencia_id);
            $this->assertSame([1, 4], $contrato->dias, 'Dias gravados em ordem.');
            $this->assertSame('Seg, Qui', $contrato->diasRotulo());
            $this->assertNull($contrato->fim);
            $this->assertTrue($contrato->vigente());
        });

        $this->get(self::URL.'/contratos')->assertOk()->assertSee('2x por semana')->assertSee('Seg, Qui')->assertSee('Vigente');
    }

    public function test_admin_da_instalacao_escolhe_a_agencia(): void
    {
        $admin = $this->tenant->run(fn () => User::where('perfil', 'admin_instalacao')->first());
        $this->actingAs($admin);

        $this->post(self::URL.'/contratos', $this->dados())->assertSessionHasErrors('agencia_id');
        $this->post(self::URL.'/contratos', $this->dados(['agencia_id' => $this->agencia->id]))->assertSessionHasNoErrors();

        $this->tenant->run(fn () => $this->assertSame($this->agencia->id, ContratoVisita::sole()->agencia_id));
    }

    public function test_dias_precisam_bater_com_a_frequencia(): void
    {
        $this->actingAs($this->adminAgencia());

        $this->post(self::URL.'/contratos', $this->dados(['frequencia_semana' => 3, 'dias' => [1, 3]]))
            ->assertSessionHasErrors(['dias' => 'Marque exatamente 3 dia(s) da semana, um para cada visita da frequência contratada.']);
        $this->post(self::URL.'/contratos', $this->dados(['frequencia_semana' => 1, 'dias' => [1, 3]]))
            ->assertSessionHasErrors('dias');
        $this->post(self::URL.'/contratos', $this->dados(['dias' => []]))
            ->assertSessionHasErrors(['dias' => 'Marque os dias da semana das visitas.']);
        $this->post(self::URL.'/contratos', $this->dados(['dias' => [2, 2]]))
            ->assertSessionHasErrors('dias.0');
        $this->post(self::URL.'/contratos', $this->dados(['dias' => [0, 8]]))
            ->assertSessionHasErrors(['dias.0', 'dias.1']);
        $this->post(self::URL.'/contratos', $this->dados(['frequencia_semana' => 4, 'dias' => [1, 2, 3, 4]]))
            ->assertSessionHasErrors(['frequencia_semana' => 'A frequência deve ser 1, 2 ou 3 visitas por semana.']);
        $this->post(self::URL.'/contratos', $this->dados(['inicio' => '2026-10-10', 'fim' => '2026-10-09']))
            ->assertSessionHasErrors(['fim' => 'O fim da vigência não pode ser antes do início.']);

        $this->tenant->run(fn () => $this->assertSame(0, ContratoVisita::count()));

        $this->post(self::URL.'/contratos', $this->dados(['frequencia_semana' => 3, 'dias' => [1, 3, 5]]))->assertSessionHasNoErrors();
        $this->tenant->run(fn () => $this->assertSame([1, 3, 5], ContratoVisita::sole()->dias));
    }

    public function test_pdv_pode_ter_contratos_de_varias_industrias_mas_nao_dois_vigentes_da_mesma(): void
    {
        $this->actingAs($this->adminAgencia());

        $this->post(self::URL.'/contratos', $this->dados())->assertSessionHasNoErrors();
        $this->post(self::URL.'/contratos', $this->dados(['industria_id' => $this->marcaB->id, 'frequencia_semana' => 1, 'dias' => [5]]))
            ->assertSessionHasNoErrors();

        // Mesma indústria, vigência que se cruza: recusado.
        $this->post(self::URL.'/contratos', $this->dados(['inicio' => '2027-01-01']))
            ->assertSessionHasErrors(['industria_id' => 'Este PDV já tem um contrato ativo desta indústria nesse período (agência Xyz, desde 01/10/2026, sem data de fim). Encerre o anterior ou ajuste a vigência.']);

        // Encerra o primeiro contrato e cadastra o novo depois do fim: permitido.
        $primeiro = $this->tenant->run(fn () => ContratoVisita::where('industria_id', $this->marcaA->id)->sole());
        $this->put(self::URL."/contratos/{$primeiro->id}", $this->dados(['fim' => '2026-12-31']))->assertSessionHasNoErrors();
        $this->post(self::URL.'/contratos', $this->dados(['inicio' => '2027-01-01', 'dias' => [2, 5]]))->assertSessionHasNoErrors();

        // Um contrato inativo não bloqueia.
        $this->post(self::URL.'/contratos', $this->dados(['industria_id' => $this->marcaB->id, 'frequencia_semana' => 1, 'dias' => [3], 'ativo' => '0']))
            ->assertSessionHasNoErrors();

        // Editar o próprio contrato não conflita com ele mesmo.
        $this->put(self::URL."/contratos/{$primeiro->id}", $this->dados(['fim' => '2026-12-31', 'dias' => [2, 4]]))->assertSessionHasNoErrors();

        $this->tenant->run(function () {
            $this->assertSame(4, ContratoVisita::where('pdv_id', $this->pdv->id)->count());
            $this->assertSame(2, ContratoVisita::vigentesEm(now())->count());
            $this->assertSame(2, ContratoVisita::vigentesEm(Carbon::parse('2027-02-01'))->count());
        });
    }

    public function test_vigencia(): void
    {
        $this->tenant->run(function () {
            $contrato = new ContratoVisita(['inicio' => '2026-10-14', 'fim' => '2026-10-20', 'ativo' => true]);
            $this->assertTrue($contrato->vigente());
            $this->assertTrue($contrato->vigente(Carbon::parse('2026-10-20 23:00')));
            $this->assertFalse($contrato->vigente(Carbon::parse('2026-10-21')));
            $this->assertFalse($contrato->vigente(Carbon::parse('2026-10-13')));

            $contrato->ativo = false;
            $this->assertFalse($contrato->vigente());
        });
    }

    public function test_cada_perfil_ve_so_os_seus_contratos(): void
    {
        $outraAgencia = $this->tenant->run(fn () => Agencia::create(['nome' => 'Outra agência']));
        $outroPdv = $this->tenant->run(fn () => Pdv::create(['cnpj' => self::cnpj(2), 'nome' => 'Mercado do Bairro', 'cidade' => 'Bento', 'uf' => 'RS']));

        $this->contrato(); // Marca A, agência XYZ, Supermercado Central
        $contratoOutraAgencia = $this->contrato(['industria_id' => $this->marcaB->id, 'agencia_id' => $outraAgencia->id, 'pdv_id' => $outroPdv->id]);

        $supervisor = $this->criarUsuario($this->tenant, Perfil::Supervisor, ['agencia_id' => $this->agencia->id]);
        $this->actingAs($supervisor)->get(self::URL.'/contratos')
            ->assertOk()->assertSee('Supermercado Central')->assertDontSee('Mercado do Bairro')->assertDontSee('Novo contrato');
        $this->actingAs($supervisor)->get(self::URL.'/contratos/create')->assertForbidden();

        foreach ([Perfil::GerenteTrade, Perfil::Representante] as $perfil) {
            $usuario = $this->criarUsuario($this->tenant, $perfil, ['industria_id' => $this->marcaB->id]);
            $this->actingAs($usuario)->get(self::URL.'/contratos')
                ->assertOk()->assertSee('Mercado do Bairro')->assertDontSee('Supermercado Central');
            $this->actingAs($usuario)->post(self::URL.'/contratos', $this->dados())->assertForbidden();
        }

        // Admin da agência não edita contrato de outra agência.
        $admin = $this->adminAgencia();
        $this->actingAs($admin)->get(self::URL."/contratos/{$contratoOutraAgencia->id}/edit")->assertForbidden();
        $this->actingAs($admin)->put(self::URL."/contratos/{$contratoOutraAgencia->id}", $this->dados())->assertForbidden();

        $promotor = $this->criarUsuario($this->tenant, Perfil::Promotor, ['agencia_id' => $this->agencia->id, 'tipo_vinculo' => 'clt']);
        $this->actingAs($promotor)->get(self::URL.'/contratos')->assertForbidden();
    }

    public function test_contrato_libera_o_pdv_para_a_industria(): void
    {
        $gerente = $this->criarUsuario($this->tenant, Perfil::GerenteTrade, ['industria_id' => $this->marcaA->id]);
        $this->actingAs($gerente)->get(self::URL.'/pdvs')->assertDontSee('Supermercado Central');

        $this->actingAs($this->adminAgencia())->post(self::URL.'/contratos', $this->dados())->assertSessionHasNoErrors();

        $this->actingAs($gerente)->get(self::URL.'/pdvs')->assertSee('Supermercado Central');
    }

    public function test_filtros(): void
    {
        $outroPdv = $this->tenant->run(fn () => Pdv::create(['cnpj' => self::cnpj(2), 'nome' => 'Mercado do Bairro', 'cidade' => 'Bento', 'uf' => 'RS']));
        $this->contrato(['frequencia_semana' => 1, 'dias' => [2]]);
        $this->contrato(['pdv_id' => $outroPdv->id, 'industria_id' => $this->marcaB->id, 'frequencia_semana' => 3, 'dias' => [1, 3, 5], 'inicio' => '2026-11-01']);
        $this->contrato(['pdv_id' => $outroPdv->id, 'frequencia_semana' => 2, 'dias' => [2, 4], 'fim' => '2026-06-30']);

        $this->actingAs($this->adminAgencia());

        $this->get(self::URL.'/contratos?situacao=vigentes')->assertSee('Supermercado Central')->assertDontSee('Mercado do Bairro');
        $this->get(self::URL.'/contratos?situacao=futuros')->assertSee('Mercado do Bairro')->assertSee('A começar')->assertDontSee('Supermercado Central');
        $this->get(self::URL.'/contratos?situacao=encerrados')->assertSee('Encerrado')->assertSee('Ter, Qui')->assertDontSee('Supermercado Central');
        $this->get(self::URL.'/contratos?frequencia=3')->assertSee('Seg, Qua, Sex')->assertDontSee('Supermercado Central');
        $this->get(self::URL.'/contratos?industria_id='.$this->marcaB->id)->assertSee('Mercado do Bairro')->assertDontSee('Supermercado Central');
        $this->get(self::URL.'/contratos?busca=bairro')->assertSee('Mercado do Bairro')->assertDontSee('Supermercado Central');
        $this->get(self::URL.'/contratos?pdv_id='.$this->pdv->id)->assertSee('Contratos de visita — Supermercado Central');
        $this->get(self::URL.'/pdvs')->assertSee('/contratos?pdv_id='.$this->pdv->id, false);
    }
}
