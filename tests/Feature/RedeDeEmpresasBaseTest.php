<?php

namespace Tests\Feature;

use App\Enums\ModoEmpresa;
use App\Enums\TipoInstalacao;
use App\Models\Admin;
use App\Models\Tenant;
use App\Models\Vinculo;
use App\Services\ProvisionarCliente;
use App\Services\Rede\Autocadastro;
use App\Services\Rede\Vinculos;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Fase 9, item 9.1: CNPJ, modo e dono das empresas; situação, relação e permissões do vínculo. */
class RedeDeEmpresasBaseTest extends TestCase
{
    private const CNPJ = '11.222.333/0001-81';

    private function provisionar(string $id, string $tipo, ?string $cnpj): Tenant
    {
        $r = app(ProvisionarCliente::class)->executar([
            'id' => $id, 'nome' => ucfirst($id), 'tipo' => $tipo, 'cnpj' => $cnpj,
            'dominios' => [$id.'.test'], 'admin_nome' => 'Admin', 'admin_email' => "admin@{$id}.test",
        ]);
        tenancy()->end();

        return $r['tenant'];
    }

    public function test_empresa_nasce_propria_criada_pelo_super_admin_com_cnpj_so_digitos(): void
    {
        $t = $this->provisionar('marcax', 'industria', self::CNPJ);

        $t = Tenant::find($t->id);
        $this->assertSame('11222333000181', $t->cnpj);
        $this->assertSame('11.222.333/0001-81', $t->cnpjFormatado());
        $this->assertSame(ModoEmpresa::Propria, $t->modo);
        $this->assertSame('super_admin', $t->origem);
        $this->assertNull($t->dono_tenant_id);
    }

    public function test_cnpj_e_unico_por_tipo_e_precisa_ser_valido(): void
    {
        $this->provisionar('marcax', 'industria', self::CNPJ);
        $this->provisionar('agenciax', 'agencia', self::CNPJ); // outro tipo: permitido

        try {
            $this->provisionar('marcay', 'industria', '11222333000181');
            $this->fail('CNPJ repetido deveria ser recusado');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cnpj', $e->errors());
        }
        try {
            $this->provisionar('marcaz', 'industria', '11.222.333/0001-00');
            $this->fail('CNPJ inválido deveria ser recusado');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cnpj', $e->errors());
        }
        $this->provisionar('semcnpj', 'industria', null);
        $this->provisionar('semcnpj2', 'industria', null); // sem CNPJ não colide
        $this->assertSame(4, Tenant::count());
    }

    public function test_vinculo_do_super_admin_nasce_ativo_cliente_com_catalogo_liberado(): void
    {
        $industria = $this->criarCliente('gaboardi');
        $agencia = $this->criarCliente('sul', 'agencia');
        $this->vincular($industria, $agencia);
        tenancy()->end();

        $v = Vinculo::sole();
        $this->assertSame(Vinculo::ATIVO, $v->status);
        $this->assertSame('super_admin', $v->origem);
        $this->assertSame('cliente', $v->relacao);
        $this->assertTrue($v->agenciaMantemCatalogo(), 'padrão: agência pode manter catálogo');
        $this->assertNotNull($v->decidido_em);

        app(Vinculos::class)->atualizar($v, null, false);
        $this->assertSame(Vinculo::ENCERRADO, $v->fresh()->status);
        app(Vinculos::class)->atualizar($v->fresh(), null, true);
        $this->assertSame(Vinculo::ATIVO, $v->fresh()->status);

        $v->update(['permissoes' => ['agencia_mantem_catalogo' => false]]);
        $this->assertFalse($v->fresh()->agenciaMantemCatalogo());
    }

    public function test_super_admin_informa_e_edita_o_cnpj(): void
    {
        $cliente = $this->criarCliente('marcaw');
        $outro = $this->criarCliente('marcav');
        tenancy()->end();
        $outro->update(['cnpj' => '11222333000181']);
        $this->actingAs(Admin::create(['nome' => 'R', 'email' => 'r@t.com', 'password' => 'senha1234']), 'admin');

        $this->put("http://central.test/clientes/{$cliente->id}/marca", ['cnpj' => self::CNPJ])->assertSessionHasErrors('cnpj');
        $this->put("http://central.test/clientes/{$cliente->id}/marca", ['cnpj' => '45.723.174/0001-10'])->assertRedirect();
        $this->assertSame('45723174000110', Tenant::find($cliente->id)->cnpj);
        $this->get("http://central.test/clientes/{$cliente->id}/marca")->assertSee('45.723.174/0001-10');
    }

    public function test_rede_de_jobs_nasce_desligada_e_so_o_super_admin_libera_para_agencia(): void
    {
        $agencia = $this->criarCliente('semequipe', 'agencia');
        $industria = $this->criarCliente('marcajob');
        tenancy()->end();
        $this->assertFalse(Tenant::find($agencia->id)->redeJobsHabilitada());
        $this->actingAs(Admin::create(['nome' => 'R', 'email' => 'r@t.com', 'password' => 'senha1234']), 'admin');

        $this->get("http://central.test/clientes/{$agencia->id}/marca")->assertSee('Liberar a rede de jobs');
        $this->get("http://central.test/clientes/{$industria->id}/marca")->assertDontSee('Liberar a rede de jobs');

        $this->put("http://central.test/clientes/{$agencia->id}/marca", ['rede_jobs' => 1])->assertRedirect();
        $this->assertTrue(Tenant::find($agencia->id)->redeJobsHabilitada());
        // Indústria nunca habilita, mesmo que o campo seja enviado.
        $this->put("http://central.test/clientes/{$industria->id}/marca", ['rede_jobs' => 1])->assertRedirect();
        $this->assertFalse(Tenant::find($industria->id)->redeJobsHabilitada());
        // Desmarcar desliga.
        $this->put("http://central.test/clientes/{$agencia->id}/marca", [])->assertRedirect();
        $this->assertFalse(Tenant::find($agencia->id)->redeJobsHabilitada());
    }

    public function test_lista_de_clientes_mostra_cnpj_modo_e_origem(): void
    {
        $agencia = $this->criarCliente('agsul', 'agencia');
        tenancy()->end();
        $agencia->update(['cnpj' => '45723174000110']);
        app(Autocadastro::class)->criar($agencia, TipoInstalacao::Industria, ModoEmpresa::Gerida, ['nome' => 'Doces Serra', 'cnpj' => self::CNPJ, 'subdominio' => 'docesserra', 'admin_nome' => 'A', 'admin_email' => 'a@a.com']);
        $this->actingAs(Admin::create(['nome' => 'R', 'email' => 'r@t.com', 'password' => 'senha1234']), 'admin');

        $this->get('http://central.test/clientes')->assertOk()
            ->assertSee('45.723.174/0001-10')->assertSee('Gerida pela agência · Agsul')->assertSee('Criada pelo painel de agsul');
    }
}
