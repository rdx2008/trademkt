<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\ContratoVisita;
use App\Models\Industria;
use App\Models\Pdv;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vinculo;
use App\Services\Rede\Regioes;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Indústria com uma agência por região: as regiões do vínculo decidem quem pode ter o contrato do PDV. */
class RegioesPorAgenciaTest extends TestCase
{
    private const URL = 'http://gaboardi.test';

    private Tenant $industria;

    private Agencia $sul;

    private Agencia $norte;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->industria = $this->criarCliente('gaboardi');
        $agSul = $this->criarCliente('agsul', 'agencia');
        $agNorte = $this->criarCliente('agnorte', 'agencia');
        $this->sul = $this->vincular($this->industria, $agSul, [['uf' => 'RS'], ['uf' => 'SC']]);
        $this->norte = $this->vincular($this->industria, $agNorte, [['uf' => 'SP', 'cidade' => 'Campinas']]);
        tenancy()->end();
        $this->admin = $this->industria->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
    }

    private function contrato(int $agenciaId, Pdv $pdv): TestResponse
    {
        $marca = $this->industria->run(fn () => Industria::first());

        return $this->actingAs($this->admin)->post(self::URL.'/contratos', [
            'pdv_id' => $pdv->id, 'industria_id' => $marca->id, 'agencia_id' => $agenciaId,
            'frequencia_semana' => 1, 'dias' => [3], 'inicio' => '2026-11-20', 'ativo' => 1,
        ]);
    }

    public function test_contrato_so_aceita_agencia_que_atende_a_regiao_do_pdv(): void
    {
        $caxias = $this->industria->run(fn () => Pdv::create(['cnpj' => self::cnpj(1), 'nome' => 'Mercado Caxias', 'cidade' => 'Caxias do Sul', 'uf' => 'RS']));
        $this->contrato($this->norte->id, $caxias)->assertSessionHasErrors('agencia_id');
        $this->contrato($this->sul->id, $caxias)->assertSessionHasNoErrors();

        $campinas = $this->industria->run(fn () => Pdv::create(['cnpj' => self::cnpj(2), 'nome' => 'Mercado Campinas', 'cidade' => 'Campinas', 'uf' => 'SP']));
        $santos = $this->industria->run(fn () => Pdv::create(['cnpj' => self::cnpj(3), 'nome' => 'Mercado Santos', 'cidade' => 'Santos', 'uf' => 'SP']));
        $this->contrato($this->norte->id, $campinas)->assertSessionHasNoErrors();
        $this->contrato($this->norte->id, $santos)->assertSessionHasErrors('agencia_id'); // só Campinas, não o estado

        $this->assertSame(2, $this->industria->run(fn () => ContratoVisita::count()));
    }

    public function test_industria_edita_as_regioes_de_cada_agencia(): void
    {
        $vinculo = Vinculo::where('agencia_tenant_id', 'agnorte')->sole();
        $this->actingAs($this->admin)->get(self::URL.'/empresas-vinculadas')->assertOk()->assertSee('Campinas, SP')->assertSee('Editar regiões');

        $this->patch(self::URL."/empresas-vinculadas/{$vinculo->id}/regioes", ['regioes' => "SP\nRibeirão Preto - SP\n"])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame([['uf' => 'SP', 'cidade' => null], ['uf' => 'SP', 'cidade' => 'Ribeirão Preto']], $vinculo->fresh()->regioes);

        $this->patch(self::URL."/empresas-vinculadas/{$vinculo->id}/regioes", ['regioes' => 'XX'])->assertSessionHasErrors();
        $this->patch(self::URL."/empresas-vinculadas/{$vinculo->id}/regioes", ['regioes' => ''])->assertSessionHasNoErrors();
        $this->assertNull($vinculo->fresh()->regioes, 'vazio = todas as regiões');
    }

    public function test_regras_de_atendimento(): void
    {
        $this->assertTrue(Regioes::atende(null, 'AM', 'Manaus'), 'sem regiões atende todas');
        $this->assertTrue(Regioes::atende([['uf' => 'RS', 'cidade' => null]], 'RS', 'Pelotas'));
        $this->assertTrue(Regioes::atende([['uf' => 'SP', 'cidade' => 'Ribeirão Preto']], 'sp', 'ribeirao preto'), 'sem acento e maiúsculas');
        $this->assertFalse(Regioes::atende([['uf' => 'SP', 'cidade' => 'Campinas']], 'SP', 'Santos'));
        $this->assertTrue(Regioes::atende([['uf' => 'SP', 'cidade' => 'Campinas']], null, null), 'PDV sem UF não é barrado');
        $this->assertSame([['uf' => 'RS', 'cidade' => null], ['uf' => 'SC', 'cidade' => 'Chapecó']], Regioes::doTexto("rs\n\nChapecó - SC"));
    }
}
