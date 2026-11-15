<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Tenant;
use App\Services\Rede\Operacao;
use App\Support\Fuso;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Cada empresa no seu fuso: o Brasil tem quatro. */
class FusoHorarioTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dia_de_hoje_segue_o_fuso_da_empresa_e_volta_ao_padrao_ao_sair(): void
    {
        $acre = $this->criarCliente('acre');
        $acre->update(['fuso' => 'America/Rio_Branco']);
        $sp = $this->criarCliente('saopaulo');
        tenancy()->end();

        // 01:30 de 15/10 em Brasília = 23:30 de 14/10 no Acre.
        Carbon::setTestNow(Carbon::parse('2026-10-15 01:30:00', 'America/Sao_Paulo'));

        $this->assertSame('2026-10-14', $acre->run(fn () => today()->toDateString()));
        $this->assertSame('America/Rio_Branco', $acre->run(fn () => config('app.timezone')));
        $this->assertSame('2026-10-15', $sp->run(fn () => today()->toDateString()), 'empresa sem fuso usa o padrão');
        $this->assertSame(Fuso::padrao(), config('app.timezone'), 'fora das empresas volta ao padrão');
        $this->assertSame('2026-10-15', today()->toDateString());
    }

    public function test_operacao_da_industria_aberta_pela_agencia_usa_o_fuso_da_industria(): void
    {
        $industria = $this->criarCliente('manausind');
        $industria->update(['fuso' => 'America/Manaus']);
        $agencia = $this->criarCliente('agsp', 'agencia');
        $this->vincular($industria, $agencia);
        tenancy()->end();

        tenancy()->initialize($agencia);
        $this->assertSame('America/Sao_Paulo', config('app.timezone'));
        $operacao = app(Operacao::class);
        $operacao->abrir($agencia, $industria);
        $this->assertSame('America/Manaus', config('app.timezone'), 'as visitas são da indústria');
        $operacao->fechar();
        $this->assertSame('America/Sao_Paulo', config('app.timezone'));
        tenancy()->end();
    }

    public function test_super_admin_escolhe_o_fuso_e_recusa_valor_invalido(): void
    {
        $cliente = $this->criarCliente('nort');
        tenancy()->end();
        $this->actingAs(Admin::create(['nome' => 'R', 'email' => 'r@t.com', 'password' => 'senha1234']), 'admin');

        $this->get("http://central.test/clientes/{$cliente->id}/marca")->assertOk()->assertSee('Fuso horário')->assertSee('Acre e oeste do Amazonas');
        $this->put("http://central.test/clientes/{$cliente->id}/marca", ['fuso' => 'Europe/Lisbon'])->assertSessionHasErrors('fuso');
        $this->put("http://central.test/clientes/{$cliente->id}/marca", ['fuso' => 'America/Manaus', 'white_label' => 1])->assertRedirect();

        $this->assertSame('America/Manaus', Tenant::find($cliente->id)->fuso);
        $this->assertSame('America/Manaus', Fuso::de(Tenant::find($cliente->id)));
    }
}
