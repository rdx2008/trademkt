<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Admin;
use App\Models\Agencia;
use App\Models\ContratoVisita;
use App\Models\Industria;
use App\Models\Pdv;
use App\Models\Rede\AcessoParceria;
use App\Models\Rede\AgenciaRede;
use App\Models\Rede\Parceria;
use App\Models\Sku;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Rede\PerfilAgencia;
use Tests\TestCase;

class ParceriaRedeTest extends TestCase
{
    private Tenant $industria;

    private Tenant $agencia;

    private AgenciaRede $perfil;

    private User $adminIndustria;

    private User $adminAgencia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->industria = $this->criarCliente('marca', 'industria');
        $this->agencia = $this->criarCliente('agencia', 'agencia');
        $this->adminIndustria = $this->industria->run(fn () => User::first());
        $this->adminAgencia = $this->agencia->run(fn () => User::first());
        $this->perfil = new AgenciaRede(['tenant_id' => 'agencia', 'agencia_local_id' => $this->adminAgencia->agencia_id]);
        app(PerfilAgencia::class)->salvar($this->perfil, ['regioes' => "RS\nSC:Chapecó", 'apresentacao' => 'Equipe especializada no Sul', 'aprovada' => true, 'disponivel' => true], true);
    }

    private function solicitar(): Parceria
    {
        $this->actingAs($this->adminIndustria)->post('http://marca.test/parcerias', ['agencia_rede_id' => $this->perfil->id, 'regiao' => 'Caxias do Sul, RS', 'mensagem' => 'Atendimento de bazar'])->assertRedirect()->assertSessionHasNoErrors();

        return Parceria::sole();
    }

    private function aceitar(): Parceria
    {
        $parceria = $this->solicitar();
        $this->actingAs($this->adminAgencia)->patch('http://agencia.test/parcerias/'.$parceria->id.'/responder', ['decisao' => 'aceita'])->assertRedirect()->assertSessionHasNoErrors();

        return $parceria->fresh();
    }

    public function test_busca_por_cidade_sem_acento_e_area_estadual(): void
    {
        $this->actingAs($this->adminIndustria)->get('http://marca.test/parcerias?uf=SC&cidade=Chapeco')->assertOk()->assertSee('Equipe especializada no Sul');
        $this->get('http://marca.test/parcerias?uf=RS&cidade=Porto%20Alegre')->assertOk()->assertSee('Equipe especializada no Sul');
        $this->get('http://marca.test/parcerias?uf=SC&cidade=Florianopolis')->assertOk()->assertDontSee('Equipe especializada no Sul');
        $this->getJson('http://marca.test/parcerias?cidade=Chapeco')->assertUnprocessable();
    }

    public function test_agencia_nao_aprovada_indisponivel_ou_suspensa_nao_pode_ser_solicitada(): void
    {
        foreach ([['aprovada' => false, 'disponivel' => true], ['aprovada' => true, 'disponivel' => false]] as $dados) {
            $this->perfil->update($dados);
            $this->actingAs($this->adminIndustria)->postJson('http://marca.test/parcerias', ['agencia_rede_id' => $this->perfil->id, 'regiao' => 'RS'])->assertUnprocessable();
            $this->get('http://marca.test/parcerias')->assertOk()->assertDontSee('Equipe especializada no Sul');
        }
        $this->perfil->update(['aprovada' => true, 'disponivel' => true]);
        $this->agencia->update(['ativo' => false]);
        $this->postJson('http://marca.test/parcerias', ['agencia_rede_id' => $this->perfil->id, 'regiao' => 'RS'])->assertUnprocessable();
        $this->assertSame(0, Parceria::count());
    }

    public function test_aceite_cria_apenas_representacao_local_sem_contratos_e_sem_duplicacao(): void
    {
        $parceria = $this->aceitar();
        $this->assertSame('aceita', $parceria->status);
        $this->industria->run(function () {
            $this->assertSame(1, Agencia::where('agencia_rede_id', $this->perfil->id)->count());
            $this->assertSame(0, ContratoVisita::count());
        });
        $this->actingAs($this->adminAgencia)->patchJson('http://agencia.test/parcerias/'.$parceria->id.'/responder', ['decisao' => 'aceita'])->assertConflict();
        $this->actingAs($this->adminIndustria)->get('http://marca.test/agencias')->assertOk()->assertSee('Agencia');
        $this->postJson('http://marca.test/parcerias', ['agencia_rede_id' => $this->perfil->id, 'regiao' => 'RS'])->assertUnprocessable();
    }

    public function test_recusa_exige_motivo_e_permite_nova_solicitacao(): void
    {
        $parceria = $this->solicitar();
        $this->actingAs($this->adminAgencia)->patchJson('http://agencia.test/parcerias/'.$parceria->id.'/responder', ['decisao' => 'recusada'])->assertUnprocessable();
        $this->patch('http://agencia.test/parcerias/'.$parceria->id.'/responder', ['decisao' => 'recusada', 'resposta' => 'Sem equipe disponível'])->assertSessionHasNoErrors();
        $this->assertSame('recusada', $parceria->fresh()->status);
        $this->industria->run(fn () => $this->assertSame(0, Agencia::count()));
        $this->solicitar();
        $this->assertSame(1, Parceria::count());
    }

    public function test_outra_agencia_nao_responde_e_industria_nao_se_auto_aceita(): void
    {
        $parceria = $this->solicitar();
        $this->patchJson('http://marca.test/parcerias/'.$parceria->id.'/responder', ['decisao' => 'aceita'])->assertForbidden();
        tenancy()->end();
        $outra = $this->criarCliente('outra', 'agencia');
        $admin = $outra->run(fn () => User::first());
        AgenciaRede::create(['tenant_id' => 'outra', 'agencia_local_id' => $admin->agencia_id, 'aprovada' => true]);
        $this->actingAs($admin)->patchJson('http://outra.test/parcerias/'.$parceria->id.'/responder', ['decisao' => 'aceita'])->assertForbidden();
        $this->assertSame('pendente', $parceria->fresh()->status);
    }

    public function test_cancelamento_e_isolamento_das_listagens(): void
    {
        $parceria = $this->solicitar();
        tenancy()->end();
        $outra = $this->criarCliente('outramarca', 'industria');
        $this->actingAs($outra->run(fn () => User::first()))->get('http://outramarca.test/parcerias')->assertOk()->assertDontSee('Caxias do Sul, RS');
        $this->deleteJson('http://outramarca.test/parcerias/'.$parceria->id)->assertNotFound();
        $this->actingAs($this->adminIndustria)->delete('http://marca.test/parcerias/'.$parceria->id)->assertSessionHasNoErrors();
        $this->assertSame('cancelada', $parceria->fresh()->status);
    }

    public function test_agencia_atualiza_perfil_sem_aprovar_a_si_mesma(): void
    {
        $this->perfil->update(['aprovada' => false]);
        $this->actingAs($this->adminAgencia)->put('http://agencia.test/parcerias/perfil/agencia', ['regioes' => 'SP:São Paulo', 'aprovada' => true, 'disponivel' => true])->assertSessionHasNoErrors();
        $this->assertFalse($this->perfil->fresh()->aprovada);
        $this->assertSame('sao paulo', $this->perfil->regioes()->sole()->cidade_busca);
    }

    public function test_acesso_pelo_painel_cria_admin_da_propria_agencia_e_token_de_uso_unico(): void
    {
        $parceria = $this->aceitar();
        $resposta = $this->actingAs($this->adminAgencia)->post('http://agencia.test/parcerias/'.$parceria->id.'/abrir')->assertRedirect();
        $url = $resposta->headers->get('Location');
        $this->assertStringStartsWith('http://marca.test/parcerias/entrar?ticket=', $url);
        $this->assertSame(1, AcessoParceria::count());
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertRedirect('http://marca.test/painel');
        $this->assertSame(0, AcessoParceria::count());
        $usuario = $this->industria->run(fn () => User::whereNotNull('parceiro_origem_tenant')->sole());
        $this->assertSame(Perfil::AdminAgencia, $usuario->perfil);
        $this->assertSame('agencia', $usuario->parceiro_origem_tenant);
        $this->get('http://marca.test/painel')->assertOk()->assertSee('Voltar à minha agência');
        $this->getJson('http://marca.test/pdvs/create')->assertForbidden();
        $this->get($url)->assertForbidden();
    }

    public function test_ticket_nao_pode_ser_usado_em_outro_cliente_nem_apos_expirar(): void
    {
        $parceria = $this->aceitar();
        $url = $this->actingAs($this->adminAgencia)->post('http://agencia.test/parcerias/'.$parceria->id.'/abrir')->headers->get('Location');
        tenancy()->end();
        $outra = $this->criarCliente('outramarca', 'industria');
        $this->app['auth']->forgetGuards();
        $this->get(str_replace('marca.test', 'outramarca.test', $url))->assertForbidden();
        AcessoParceria::query()->update(['expira_em' => now()->subMinute()]);
        $this->get($url)->assertForbidden();
    }

    public function test_suspender_agencia_ou_desativar_usuario_origem_bloqueia_sessao_aberta(): void
    {
        $parceria = $this->aceitar();
        $url = $this->actingAs($this->adminAgencia)->post('http://agencia.test/parcerias/'.$parceria->id.'/abrir')->headers->get('Location');
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertRedirect();
        $this->agencia->update(['ativo' => false]);
        $this->getJson('http://marca.test/painel')->assertForbidden();
        $this->agencia->update(['ativo' => true]);
        $this->agencia->run(fn () => User::whereKey($this->adminAgencia->id)->update(['ativo' => false]));
        $this->getJson('http://marca.test/painel')->assertForbidden();
    }

    public function test_telas_do_diretorio_da_agencia_e_do_super_admin(): void
    {
        $this->solicitar();
        $industria = $this->get('http://marca.test/parcerias')->assertOk()->assertSee('Aguardando resposta');
        $this->salvarPrevia('industria-parcerias', $industria->getContent());
        $agencia = $this->actingAs($this->adminAgencia)->get('http://agencia.test/parcerias')->assertOk()->assertSee('Aceitar parceria')->assertSee('Atendimento de bazar');
        $this->salvarPrevia('agencia-solicitacoes', $agencia->getContent());
        $admin = Admin::create(['nome' => 'Admin central', 'email' => 'rede@central.test', 'password' => 'senha1234']);
        $central = $this->actingAs($admin, 'admin')->get('http://central.test/rede-agencias')->assertOk()->assertSee('Rede de agências');
        $this->salvarPrevia('central-diretorio', $central->getContent());
        $this->get('http://central.test/rede-agencias/'.$this->perfil->id.'/editar')->assertOk()->assertSee('Aprovar participação');
        $cliente = $this->get('http://central.test/clientes/novo')->assertOk()->assertSee('Subdomínio do sistema')->assertSee('Parceria inicial');
        $this->salvarPrevia('central-cadastro-cliente', $cliente->getContent());
    }

    public function test_super_admin_pode_solicitar_parceria_no_cadastro_sem_auto_aceitar(): void
    {
        $admin = Admin::create(['nome' => 'Admin central', 'email' => 'rede@central.test', 'password' => 'senha1234']);
        $this->actingAs($admin, 'admin')->post('http://central.test/clientes', ['id' => 'novamarca', 'nome' => 'Nova marca', 'tipo' => 'industria', 'tipo_endereco' => 'subdominio', 'subdominio' => 'novamarca', 'admin_nome' => 'Gestor', 'admin_email' => 'gestor@novamarca.test', 'agencia_rede_id' => $this->perfil->id, 'regiao_parceria' => 'RS'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('pendente', Parceria::sole()->status);
        $this->assertSame('novamarca', Parceria::sole()->industria_tenant_id);
        Tenant::find('novamarca')->run(fn () => $this->assertSame(0, Agencia::count()));
    }

    public function test_isola_pdvs_catalogo_e_equipe_ao_abrir_operacao(): void
    {
        $parceria = $this->aceitar();
        $ids = $this->industria->run(function () {
            $propria = Agencia::where('agencia_rede_id', $this->perfil->id)->sole();
            $outra = Agencia::create(['nome' => 'Prestadora privada']);
            $marca = Industria::first();
            $outraMarca = Industria::create(['nome' => 'Marca privada']);
            $ids = [];
            foreach ([[$propria, $marca, 'Loja autorizada'], [$outra, $outraMarca, 'Loja privada']] as $i => [$agencia, $industria, $nome]) {
                $pdv = Pdv::create(['cnpj' => self::cnpj($i + 1), 'nome' => $nome, 'cidade' => 'Caxias do Sul', 'uf' => 'RS']);
                $contrato = ContratoVisita::create(['pdv_id' => $pdv->id, 'industria_id' => $industria->id, 'agencia_id' => $agencia->id, 'frequencia_semana' => 1, 'dias' => [1], 'inicio' => '2026-01-01']);
                Sku::create(['industria_id' => $industria->id, 'codigo' => 'SKU'.$i, 'descricao' => $nome.' produto']);
                $ids[] = $contrato->id;
            }

            return $ids;
        });
        $url = $this->actingAs($this->adminAgencia)->post('http://agencia.test/parcerias/'.$parceria->id.'/abrir')->headers->get('Location');
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertRedirect();
        $this->get('http://marca.test/pdvs')->assertOk()->assertSee('Loja autorizada')->assertDontSee('Loja privada')->assertDontSee('Novo PDV');
        $this->get('http://marca.test/skus')->assertOk()->assertSee('Loja autorizada produto')->assertDontSee('Marca privada')->assertDontSee('Loja privada produto');
        $this->get('http://marca.test/contratos')->assertOk()->assertDontSee('Prestadora privada')->assertDontSee('Loja privada');
        $this->get('http://marca.test/contratos/'.$ids[0].'/edit')->assertOk()->assertDontSee('Prestadora privada')->assertDontSee('Marca privada');
        $this->getJson('http://marca.test/contratos/'.$ids[1].'/edit')->assertForbidden();
        $this->getJson('http://marca.test/industrias')->assertForbidden();
    }

    public function test_usuario_sem_perfil_de_gestao_nao_solicita_parceria(): void
    {
        $usuario = $this->criarUsuario($this->industria, Perfil::Representante, ['industria_id' => $this->adminIndustria->industria_id]);
        $this->actingAs($usuario)->getJson('http://marca.test/parcerias')->assertForbidden();
        $this->postJson('http://marca.test/parcerias', ['agencia_rede_id' => $this->perfil->id, 'regiao' => 'RS'])->assertForbidden();
    }

    public function test_super_admin_publica_agencia_e_regiao_invalida_nao_e_gravada(): void
    {
        $cliente = $this->criarCliente('segunda', 'agencia');
        $admin = Admin::create(['nome' => 'Admin central', 'email' => 'rede@central.test', 'password' => 'senha1234']);
        $this->actingAs($admin, 'admin')->post('http://central.test/rede-agencias', ['tenant_id' => $cliente->id, 'regioes' => "SP\nPR:Curitiba", 'aprovada' => true, 'disponivel' => true])->assertRedirect()->assertSessionHasNoErrors();
        $perfil = AgenciaRede::where('tenant_id', 'segunda')->sole();
        $this->assertTrue($perfil->aprovada);
        $this->assertSame(2, $perfil->regioes()->count());
        $this->putJson('http://central.test/rede-agencias/'.$perfil->id, ['regioes' => 'XX', 'aprovada' => false])->assertUnprocessable();
        $this->assertTrue($perfil->fresh()->aprovada);
        $this->assertSame(2, $perfil->regioes()->count());
    }

    public function test_sessao_autenticada_nao_pode_ser_reutilizada_em_outro_cliente(): void
    {
        $this->post('http://marca.test/entrar', ['email' => 'admin@marca.test', 'password' => 'senha1234'])->assertRedirect();
        $this->get('http://marca.test/painel')->assertOk();
        $this->getJson('http://agencia.test/painel')->assertForbidden();
    }

    public function test_bloqueia_token_de_colaborador_quando_aprovacao_da_agencia_e_retirada(): void
    {
        $this->aceitar();
        $agenciaId = $this->industria->run(fn () => Agencia::where('agencia_rede_id', $this->perfil->id)->value('id'));
        $promotor = $this->criarUsuario($this->industria, Perfil::Promotor, ['agencia_id' => $agenciaId]);
        $token = $this->industria->run(fn () => $promotor->createToken('teste', ['promotor'])->plainTextToken);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('http://marca.test/api/v1/me')->assertOk();
        $this->perfil->update(['aprovada' => false]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('http://marca.test/api/v1/me')->assertForbidden();
    }

    private function salvarPrevia(string $nome, string $html): void
    {
        if ($pasta = getenv('PARCERIAS_PREVIEW_DIR')) {
            if (! is_dir($pasta)) {
                mkdir($pasta, 0777, true);
            }
            file_put_contents($pasta.'/'.$nome.'.html', preg_replace('~https?://[^/]+/css/app\.css~', '/app.css', $html));
        }
    }
}
