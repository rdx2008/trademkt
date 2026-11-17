<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Admin;
use App\Models\ConsentimentoPromotor;
use App\Models\PromotorGlobal;
use App\Models\User;
use App\Rules\Cpf;
use App\Services\Promotores\IdentidadeGlobal;
use App\Support\Municipios;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Cidade do promotor pela lista do IBGE, raio, identidade central e o painel de contagem do super admin. */
class RedeDePromotoresTest extends TestCase
{
    private const CAXIAS = '4305108';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_catalogo_tem_todos_os_municipios_com_coordenada_e_acha_pelo_nome(): void
    {
        $this->assertCount(5571, Municipios::todos());
        $this->assertSame('Caxias do Sul', Municipios::achar(self::CAXIAS)['nome']);
        $this->assertCount(645, Municipios::porUf('sp'));
        $this->assertSame(self::CAXIAS, Municipios::procurar('  CAXIAS do sul ', 'rs'));
        $this->assertSame('5300108', Municipios::procurar('Brasilia', 'DF'), 'sem acento também acha');
        $this->assertNull(Municipios::procurar('Cidade Inventada', 'RS'));
        $this->assertTrue(Municipios::pertenceAUf(self::CAXIAS, 'RS'));
        $this->assertFalse(Municipios::pertenceAUf(self::CAXIAS, 'SP'));
        // Porto Alegre a Caxias do Sul: cerca de 100 km em linha reta.
        $d = Municipios::distanciaKm('4314902', self::CAXIAS);
        $this->assertGreaterThan(90, $d);
        $this->assertLessThan(110, $d);
    }

    public function test_rota_de_municipios_devolve_a_lista_da_uf(): void
    {
        $this->criarCliente('sul', 'agencia');
        tenancy()->end();
        $lista = $this->getJson('http://sul.test/municipios/rs')->assertOk()->json();
        $this->assertCount(497, $lista);
        $this->assertContains(['codigo' => self::CAXIAS, 'nome' => 'Caxias do Sul'], $lista);
        $this->getJson('http://sul.test/municipios/xx')->assertNotFound();
    }

    public function test_painel_guarda_cidade_da_lista_e_raio_e_recusa_cidade_de_outra_uf(): void
    {
        $agencia = $this->criarCliente('sul', 'agencia');
        $admin = $agencia->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
        tenancy()->end();
        $base = ['nome' => 'Maria', 'email' => 'maria@sul.test', 'cpf' => '529.982.247-25', 'tipo_vinculo' => 'clt'];

        $this->actingAs($admin)->post('http://sul.test/promotores', $base + ['uf_base' => 'SP', 'municipio_ibge' => self::CAXIAS])->assertSessionHasErrors('municipio_ibge');
        $this->post('http://sul.test/promotores', $base + ['uf_base' => 'RS', 'municipio_ibge' => self::CAXIAS, 'raio_km' => 3])->assertSessionHasErrors('raio_km');
        $this->post('http://sul.test/promotores', $base + ['uf_base' => 'RS', 'municipio_ibge' => self::CAXIAS, 'raio_km' => 50])->assertSessionHasNoErrors();

        $maria = $agencia->run(fn () => User::where('email', 'maria@sul.test')->sole());
        $this->assertSame('Caxias do Sul', $maria->cidade_base);
        $this->assertSame(self::CAXIAS, $maria->municipio_ibge);
        $this->assertSame(50, $maria->raio_km);
        $global = PromotorGlobal::where('codigo', $maria->promotor_global_id)->sole();
        $this->assertSame(self::CAXIAS, $global->municipio_ibge);
        $this->assertEqualsWithDelta(-29.1629, (float) $global->latitude, 0.001);
        $this->assertSame(50, (int) $global->raio_km);
    }

    public function test_cidade_digitada_a_mao_vira_municipio_quando_o_nome_bate(): void
    {
        $agencia = $this->criarCliente('sul', 'agencia');
        $admin = $agencia->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
        tenancy()->end();
        $this->actingAs($admin)->post('http://sul.test/promotores', [
            'nome' => 'Joana', 'email' => 'joana@sul.test', 'cpf' => '111.444.777-35', 'tipo_vinculo' => 'clt', 'cidade_base' => 'caxias do sul', 'uf_base' => 'RS',
        ])->assertSessionHasNoErrors();
        $joana = $agencia->run(fn () => User::where('email', 'joana@sul.test')->sole());
        $this->assertSame(self::CAXIAS, $joana->municipio_ibge);
        $this->assertSame(30, (int) PromotorGlobal::where('codigo', $joana->promotor_global_id)->value('raio_km'), 'raio padrão');
    }

    public function test_comando_liga_os_promotores_antigos_a_rede_e_a_situacao_acompanha_o_cadastro(): void
    {
        $agencia = $this->criarCliente('sul', 'agencia');
        $admin = $agencia->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
        $antigo = $agencia->run(fn () => User::create([
            'nome' => 'Antigo', 'email' => 'antigo@sul.test', 'password' => 'senha12345', 'perfil' => Perfil::Promotor, 'ativo' => true,
            'cpf' => '52998224725', 'cpf_hash' => Cpf::hash('52998224725'), 'cidade_base' => 'Caxias do Sul', 'uf_base' => 'RS',
        ]));
        tenancy()->end();
        $this->assertSame(0, PromotorGlobal::count());

        $this->artisan('promotores:sincronizar-rede')->assertExitCode(0);
        $this->artisan('promotores:sincronizar-rede')->assertExitCode(0); // idempotente

        $global = PromotorGlobal::sole();
        $this->assertSame(self::CAXIAS, $global->municipio_ibge);
        $this->assertSame($global->codigo, $agencia->run(fn () => $antigo->fresh()->promotor_global_id));
        $this->assertSame(1, DB::connection(config('tenancy.database.central_connection'))->table('promotor_agencias')->where('ativo', true)->count());

        // Desativar o promotor reflete na contagem central.
        $this->actingAs($admin)->patch('http://sul.test/promotores/'.$antigo->id.'/ativo', ['ativo' => 0])->assertRedirect();
        $this->assertSame(0, DB::connection(config('tenancy.database.central_connection'))->table('promotor_agencias')->where('ativo', true)->count());
        $this->assertSame(1, DB::connection(config('tenancy.database.central_connection'))->table('promotor_agencias')->count());
    }

    public function test_painel_do_super_admin_conta_por_uf_cidade_e_agencia_sem_dados_pessoais(): void
    {
        $sul = $this->criarCliente('sul', 'agencia');
        $norte = $this->criarCliente('norte', 'agencia');
        tenancy()->end();
        $criar = function ($tenant, string $email, string $cpf, ?string $municipio) {
            $tenant->run(function () use ($email, $cpf, $municipio) {
                $u = User::create([
                    'nome' => 'Fulano '.$email, 'email' => $email, 'password' => 'senha12345', 'perfil' => Perfil::Promotor, 'ativo' => true,
                    'cpf' => $cpf, 'cpf_hash' => Cpf::hash($cpf), 'municipio_ibge' => $municipio,
                ]);
                app(IdentidadeGlobal::class)->vincular($u);
            });
        };
        $criar($sul, 'a@sul.test', '52998224725', self::CAXIAS);
        $criar($sul, 'b@sul.test', '11144477735', self::CAXIAS);
        $criar($norte, 'a@norte.test', '52998224725', self::CAXIAS); // mesma pessoa que a@sul
        $criar($norte, 'c@norte.test', '39053344705', null);
        $sul->run(fn () => app(IdentidadeGlobal::class)->registrarConsentimento(
            User::where('email', 'a@sul.test')->sole(), 'rede_compartilhada', 'v1', 'link_agencia', '127.0.0.1', 'teste'));
        tenancy()->end();

        $this->actingAs(Admin::create(['nome' => 'R', 'email' => 'r@t.com', 'password' => 'senha1234']), 'admin');
        $this->assertSame(3, PromotorGlobal::count(), 'mesma pessoa em duas agências conta uma vez');
        $this->assertSame(1, ConsentimentoPromotor::count());

        $html = $this->get('http://central.test/rede-de-promotores')->assertOk()->assertSee('Rede de promotores')->getContent();
        $this->assertStringContainsString('Caxias do Sul', $html);
        $this->assertStringContainsString('Sem cidade escolhida', $html);
        $this->assertStringNotContainsString('a@sul.test', $html);
        $this->assertStringNotContainsString('Fulano', $html);

        $this->get('http://central.test/rede-de-promotores?uf=RS')->assertOk()->assertSee('Por cidade · RS');
    }
}
