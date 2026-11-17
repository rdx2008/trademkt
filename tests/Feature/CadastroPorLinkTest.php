<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Configuracao;
use App\Models\ConsentimentoPromotor;
use App\Models\PromotorGlobal;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\Cpf;
use App\Support\TermoDaRede;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Cadastro do promotor pelo link da agência, com aceite do termo da rede e identidade central. */
class CadastroPorLinkTest extends TestCase
{
    private const URL = 'http://sul.test';

    private const CPF = '529.982.247-25';

    private Tenant $agencia;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->agencia = $this->criarCliente('sul', 'agencia');
        $this->admin = $this->agencia->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
    }

    private function link(): string
    {
        return self::URL.'/cadastro/'.$this->agencia->codigo;
    }

    private function ligar(bool $ligar = true): void
    {
        $this->actingAs($this->admin)->patch(self::URL.'/promotores/link-de-cadastro', ['ativo' => $ligar ? 1 : 0])->assertRedirect();
        auth()->logout();
    }

    private function dados(array $extra = []): array
    {
        return array_merge([
            'nome' => 'João Freela', 'cpf' => self::CPF, 'email' => 'joao@exemplo.com', 'telefone' => '(54) 99999-0000',
            'uf_base' => 'RS', 'municipio_ibge' => '4305108', 'raio_km' => 40,
            'password' => 'Senha12345', 'password_confirmation' => 'Senha12345', 'aceite' => 1,
        ], $extra);
    }

    public function test_link_nasce_desligado_e_so_abre_depois_que_a_agencia_liga(): void
    {
        $this->get($this->link())->assertNotFound()->assertSee('Cadastro indisponível');
        $this->post($this->link(), $this->dados())->assertNotFound();
        $this->assertSame(0, $this->agencia->run(fn () => User::where('perfil', Perfil::Promotor->value)->count()));

        $this->ligar();
        $this->get($this->link())->assertOk()->assertSee('Aceito os termos de uso compartilhado da rede da plataforma')->assertSee('opcional');

        $this->ligar(false);
        $this->get($this->link())->assertNotFound();
    }

    public function test_codigo_errado_no_link_nao_abre(): void
    {
        $this->ligar();
        $this->get(self::URL.'/cadastro/outraagencia')->assertNotFound();
    }

    public function test_promotor_se_cadastra_ativo_freelancer_sem_industria_e_com_o_aceite_registrado(): void
    {
        $this->ligar();
        $this->post($this->link(), $this->dados(), ['User-Agent' => 'TesteNavegador'])->assertRedirect(self::URL.'/convite');

        $joao = $this->agencia->run(fn () => User::where('email', 'joao@exemplo.com')->sole());
        $this->assertSame(Perfil::Promotor, $joao->perfil);
        $this->assertTrue($joao->ativo);
        $this->assertSame('freelancer', $joao->tipo_vinculo->value);
        $this->assertSame('link', $joao->origem_cadastro);
        $this->assertTrue($joao->habilitacao_restrita);
        $this->assertNotNull($joao->aceite_privacidade_em);
        $this->assertTrue(Hash::check('Senha12345', $joao->password));
        $this->assertSame('529.982.247-25', Cpf::formatar($joao->cpf));
        $this->assertNotNull($joao->promotor_global_id);

        // Identidade central e consentimento com versão do texto.
        $global = PromotorGlobal::where('codigo', $joao->promotor_global_id)->sole();
        $this->assertSame('João Freela', $global->nome);
        $c = ConsentimentoPromotor::where('promotor_global_codigo', $global->codigo)->sole();
        $this->assertSame(TermoDaRede::FINALIDADE, $c->finalidade);
        $this->assertSame('link_agencia', $c->origem);
        $this->assertSame('sul', $c->tenant_id);
        $this->assertSame(TermoDaRede::versao(TermoDaRede::padrao()), $c->versao);
        $this->assertNull($c->revogado_em);
        $this->assertNotNull($c->ip);

        // Já pode entrar no app: o login da API aceita o e-mail e a senha escolhida.
        $this->assertTrue($joao->fresh()->ativo);
    }

    public function test_foto_e_opcional_e_aceite_e_obrigatorio(): void
    {
        $this->ligar();
        $this->post($this->link(), $this->dados(['aceite' => null]))->assertSessionHasErrors('aceite');
        $this->assertSame(0, $this->agencia->run(fn () => User::where('perfil', Perfil::Promotor->value)->count()));

        $foto = UploadedFile::fake()->image('rosto.jpg', 400, 400);
        $this->post($this->link(), $this->dados(['nome' => 'Com Foto', 'email' => 'foto@exemplo.com', 'cpf' => '111.444.777-35']) + ['foto' => $foto])->assertSessionHasNoErrors();
        $comFoto = $this->agencia->run(fn () => User::where('email', 'foto@exemplo.com')->sole());
        $this->assertNotNull($comFoto->foto);

        $this->post($this->link(), $this->dados())->assertSessionHasNoErrors();
        $this->assertNull($this->agencia->run(fn () => User::where('email', 'joao@exemplo.com')->value('foto')));
    }

    public function test_cpf_e_email_repetidos_sao_recusados(): void
    {
        $this->ligar();
        $this->post($this->link(), $this->dados())->assertSessionHasNoErrors();
        $this->post($this->link(), $this->dados(['email' => 'outro@exemplo.com']))->assertSessionHasErrors('cpf');
        $this->post($this->link(), $this->dados(['cpf' => '111.444.777-35']))->assertSessionHasErrors('email');
        $this->post($this->link(), $this->dados(['email' => 'novo@exemplo.com', 'cpf' => '123.456.789-00']))->assertSessionHasErrors('cpf');
        $this->assertSame(1, $this->agencia->run(fn () => User::where('perfil', Perfil::Promotor->value)->count()));
    }

    public function test_mesma_pessoa_em_outra_agencia_reaproveita_a_identidade_central(): void
    {
        $outra = $this->criarCliente('norte', 'agencia');
        $this->ligar();
        $this->post($this->link(), $this->dados())->assertSessionHasNoErrors();

        tenancy()->end();
        $outra->run(fn () => Configuracao::gravar('cadastro_link_ativo', '1'));
        $this->post('http://norte.test/cadastro/'.$outra->codigo, $this->dados(['email' => 'joao@norte.test']))->assertSessionHasNoErrors();

        $codigos = [
            $this->agencia->run(fn () => User::where('email', 'joao@exemplo.com')->value('promotor_global_id')),
            $outra->run(fn () => User::where('email', 'joao@norte.test')->value('promotor_global_id')),
        ];
        $this->assertSame($codigos[0], $codigos[1]);
        $this->assertSame(1, PromotorGlobal::count());
        $this->assertSame(2, ConsentimentoPromotor::count());
    }

    public function test_cadastro_pelo_painel_tambem_ganha_identidade_central(): void
    {
        $this->actingAs($this->admin)->post(self::URL.'/promotores', [
            'nome' => 'Maria', 'email' => 'maria@sul.test', 'cpf' => self::CPF, 'tipo_vinculo' => 'clt', 'cidade_base' => 'Caxias', 'uf_base' => 'RS',
        ])->assertRedirect();

        $codigo = $this->agencia->run(fn () => User::where('email', 'maria@sul.test')->value('promotor_global_id'));
        $this->assertNotNull($codigo);
        $this->assertSame(1, PromotorGlobal::where('codigo', $codigo)->count());
        $this->assertSame(0, ConsentimentoPromotor::count(), 'sem aceite do termo, não há consentimento');
    }

    public function test_so_o_admin_liga_o_link_e_o_termo_editado_vira_nova_versao(): void
    {
        $supervisor = $this->agencia->run(fn () => User::create([
            'nome' => 'Sup', 'email' => 'sup@sul.test', 'password' => 'senha12345', 'perfil' => Perfil::Supervisor, 'ativo' => true,
        ]));
        $this->actingAs($supervisor)->get(self::URL.'/promotores/link-de-cadastro')->assertForbidden();

        $this->actingAs($this->admin)->get(self::URL.'/promotores/link-de-cadastro')->assertOk()->assertSee($this->link());
        $versaoPadrao = TermoDaRede::versao(TermoDaRede::padrao());

        $this->put(self::URL.'/promotores/link-de-cadastro/termo', ['termo' => 'Texto próprio da agência.'])->assertRedirect();
        auth()->logout();
        $this->ligar();
        $this->get($this->link())->assertSee('Texto próprio da agência.');
        $this->post($this->link(), $this->dados())->assertSessionHasNoErrors();
        $versao = ConsentimentoPromotor::sole()->versao;
        $this->assertNotSame($versaoPadrao, $versao);
        $this->assertSame(TermoDaRede::versao('Texto próprio da agência.'), $versao);

        // Em branco volta ao padrão.
        $this->actingAs($this->admin)->put(self::URL.'/promotores/link-de-cadastro/termo', ['termo' => ''])->assertRedirect();
        $this->assertSame(TermoDaRede::padrao(), $this->agencia->run(fn () => TermoDaRede::texto()));
    }

    public function test_industria_nao_tem_link_de_cadastro(): void
    {
        $industria = $this->criarCliente('marca');
        tenancy()->end();
        $industria->run(fn () => Configuracao::gravar('cadastro_link_ativo', '1'));
        $this->get('http://marca.test/cadastro/'.$industria->codigo)->assertNotFound();
    }
}
