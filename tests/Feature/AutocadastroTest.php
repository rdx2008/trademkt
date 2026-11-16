<?php

namespace Tests\Feature;

use App\Enums\ModoEmpresa;
use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Rede\Autocadastro;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Fase 9, item 9.2: uma empresa cria outra pelo painel, com link de primeiro acesso do admin. */
class AutocadastroTest extends TestCase
{
    private function dados(array $extra = []): array
    {
        return array_merge([
            'nome' => 'Ação & Cia Indústria Ltda.',
            'cnpj' => '11.222.333/0001-81',
            'subdominio' => 'docesserra',
            'admin_nome' => 'Maria Gestora',
            'admin_email' => 'Maria@AcaoCia.com.br',
        ], $extra);
    }

    public function test_agencia_cria_industria_gerida_com_subdominio_fuso_e_convite_do_admin(): void
    {
        $agencia = $this->criarCliente('agsul', 'agencia');
        tenancy()->end();
        $agencia->update(['fuso' => 'America/Manaus']);

        tenancy()->initialize($agencia);
        $r = app(Autocadastro::class)->criar($agencia->fresh(), TipoInstalacao::Industria, ModoEmpresa::Gerida, $this->dados());
        $this->assertSame('agsul', tenant('id'), 'continua na instalação de quem criou');
        tenancy()->end();

        $nova = Tenant::find($r['tenant']->id);
        $this->assertSame('docesserra', $nova->id);
        $this->assertSame('docesserra', $nova->codigo, 'o prefixo escolhido é o código do app');
        $this->assertSame(TipoInstalacao::Industria, $nova->tipo);
        $this->assertSame(ModoEmpresa::Gerida, $nova->modo);
        $this->assertSame('agsul', $nova->dono_tenant_id);
        $this->assertSame('agsul', $nova->criado_por_tenant_id);
        $this->assertSame('autocadastro', $nova->origem);
        $this->assertSame('11222333000181', $nova->cnpj);
        $this->assertSame('America/Manaus', $nova->fuso);
        $dominio = 'docesserra.'.config('trade.dominio_clientes');
        $this->assertSame($dominio, $nova->domains()->value('domain'));

        // Indústria gerida não tem admin próprio: a agência opera; o admin só ganha acesso na entrega.
        $this->assertNull($r['convite']);
        $this->assertFalse($nova->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole()->ativo));
    }

    public function test_agencia_propria_criada_pela_industria_nao_tem_dono(): void
    {
        $industria = $this->criarCliente('marca');
        tenancy()->end();
        $r = app(Autocadastro::class)->criar($industria, TipoInstalacao::Agencia, ModoEmpresa::Propria, $this->dados(['nome' => 'Agência Nova', 'cnpj' => '45.723.174/0001-10', 'subdominio' => 'agencianova']));

        $nova = Tenant::find($r['tenant']->id);
        $this->assertSame(ModoEmpresa::Propria, $nova->modo);
        $this->assertNull($nova->dono_tenant_id);
        $this->assertSame('marca', $nova->criado_por_tenant_id);

        // O link de primeiro acesso é do domínio da empresa nova e leva ao painel.
        $dominio = $nova->domains()->value('domain');
        $this->assertStringStartsWith("http://{$dominio}/convite/", $r['convite']);
        $this->get($r['convite'])->assertOk()->assertSee('Maria');
        $this->post($r['convite'], ['password' => 'NovaSenha123', 'password_confirmation' => 'NovaSenha123', 'aceite' => 1])
            ->assertRedirect("http://{$dominio}/convite");
        $this->get("http://{$dominio}/convite")->assertOk()->assertSee('Entrar no painel')->assertDontSee('abrir o aplicativo');
        $admin = $nova->run(fn () => User::where('email', 'maria@acaocia.com.br')->sole());
        $this->assertSame(Perfil::AdminInstalacao, $admin->perfil);
        $this->assertTrue(Hash::check('NovaSenha123', $admin->password));
    }

    public function test_cnpj_obrigatorio_valido_e_sem_repetir_no_mesmo_tipo(): void
    {
        $agencia = $this->criarCliente('agnorte', 'agencia');
        tenancy()->end();
        $servico = app(Autocadastro::class);
        $servico->criar($agencia, TipoInstalacao::Industria, ModoEmpresa::Gerida, $this->dados());

        foreach ([['cnpj' => ''], ['cnpj' => '11.222.333/0001-00'], ['cnpj' => '11222333000181', 'nome' => 'Outra', 'subdominio' => 'outranome']] as $extra) {
            try {
                $servico->criar($agencia, TipoInstalacao::Industria, ModoEmpresa::Gerida, $this->dados($extra));
                $this->fail('deveria recusar o CNPJ '.$extra['cnpj']);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('cnpj', $e->errors());
            }
        }
        $this->assertSame(2, Tenant::count());
    }
}
