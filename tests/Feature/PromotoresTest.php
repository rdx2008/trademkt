<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\AuditoriaUsuario;
use App\Models\Convite;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\Cpf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * Controle de promotores da agência: cadastro com CPF (criptografado) e foto, convite de primeiro
 * acesso, histórico de alterações e importação por planilha.
 */
class PromotoresTest extends TestCase
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

    private function dados(array $extra = []): array
    {
        return array_merge([
            'nome' => 'Maria da Silva', 'email' => 'maria@sul.test', 'cpf' => self::CPF, 'telefone' => '(54) 99999-0000',
            'tipo_vinculo' => 'clt', 'cidade_base' => 'Caxias do Sul', 'uf_base' => 'RS', 'observacoes' => 'Atende aos sábados',
        ], $extra);
    }

    private function cadastrar(array $extra = [], ?UploadedFile $foto = null)
    {
        return $this->actingAs($this->admin)->post(self::URL.'/promotores', $this->dados($extra) + ($foto ? ['foto' => $foto] : []));
    }

    private function maria(): User
    {
        return $this->agencia->run(fn () => User::where('email', 'maria@sul.test')->sole());
    }

    public function test_cpf_valida_formata_mascara_e_gera_impressao_sem_expor_o_numero(): void
    {
        $this->assertTrue(Cpf::valido('529.982.247-25'));
        $this->assertTrue(Cpf::valido('52998224725'));
        $this->assertFalse(Cpf::valido('529.982.247-24'));
        $this->assertFalse(Cpf::valido('111.111.111-11'));
        $this->assertFalse(Cpf::valido('1234'));
        $this->assertSame('529.982.247-25', Cpf::formatar('52998224725'));
        $this->assertSame('***.982.247-**', Cpf::mascarar('52998224725'));
        $this->assertSame(Cpf::hash('529.982.247-25'), Cpf::hash('52998224725'));
        $this->assertStringNotContainsString('52998224725', Cpf::hash('52998224725'));
    }

    public function test_admin_cadastra_promotor_com_cpf_criptografado_foto_e_convite(): void
    {
        $r = $this->cadastrar([], UploadedFile::fake()->image('rosto.png', 800, 500));
        $r->assertSessionHasNoErrors()->assertRedirect()->assertSessionHas('convite_url');

        $m = $this->maria();
        $this->assertSame(Perfil::Promotor, $m->perfil);
        $this->assertNotNull($m->agencia_id);
        $this->assertTrue($m->ativo);
        $this->assertNull($m->aceite_privacidade_em);
        $this->assertSame('52998224725', $m->cpf, 'lido de volta pelo modelo');
        $this->assertSame('Caxias do Sul', $m->cidade_base);

        // No banco o CPF não aparece em texto puro.
        $bruto = $this->agencia->run(fn () => DB::table('users')->where('id', $m->id)->first());
        $this->assertStringNotContainsString('52998224725', (string) $bruto->cpf);
        $this->assertSame(Cpf::hash(self::CPF), $bruto->cpf_hash);
        $this->assertArrayNotHasKey('cpf', $m->toArray());

        // Foto recortada em quadrado 600x600 e guardada fora do acesso público.
        Storage::disk('local')->assertExists($m->foto);
        $this->assertStringStartsWith('promotores/', $m->foto);
        [$l, $a] = getimagesizefromstring(Storage::disk('local')->get($m->foto));
        $this->assertSame([600, 600], [$l, $a]);

        // Convite: link com 48 caracteres, só o hash fica no banco.
        $link = session('convite_url');
        $this->assertMatchesRegularExpression('#^http://sul\.test/convite/[A-Za-z0-9]{48}$#', $link);
        $token = basename($link);
        $convite = $this->agencia->run(fn () => Convite::sole());
        $this->assertSame(hash('sha256', $token), $convite->token_hash);
        $this->assertNotSame($token, $convite->token_hash);
        $this->assertTrue($convite->expira_em->isFuture());
    }

    public function test_validacoes_cpf_invalido_repetido_email_repetido_e_foto(): void
    {
        $this->cadastrar(['cpf' => '111.111.111-11'])->assertSessionHasErrors('cpf');
        $this->cadastrar(['cpf' => ''])->assertSessionHasErrors('cpf');
        $this->cadastrar(['tipo_vinculo' => 'estagiario'])->assertSessionHasErrors('tipo_vinculo');
        $this->cadastrar(['uf_base' => 'XX'])->assertSessionHasErrors('uf_base');
        $this->cadastrar([], UploadedFile::fake()->create('rosto.pdf', 10, 'application/pdf'))->assertSessionHasErrors('foto');
        $this->cadastrar([], UploadedFile::fake()->image('grande.jpg')->size(4000))->assertSessionHasErrors('foto');
        $this->agencia->run(fn () => $this->assertSame(0, User::where('perfil', 'promotor')->count()));

        $this->cadastrar()->assertSessionHasNoErrors();
        $this->cadastrar(['email' => 'outra@sul.test'])->assertSessionHasErrors('cpf'); // mesmo CPF, mesmo sem máscara
        $this->cadastrar(['cpf' => '52998224725'])->assertSessionHasErrors(['cpf', 'email']);
        $this->cadastrar(['cpf' => '168.995.350-09', 'email' => 'MARIA@sul.test'])->assertSessionHasErrors('email');
    }

    public function test_lista_mostra_cpf_mascarado_filtros_e_situacao(): void
    {
        $this->cadastrar()->assertSessionHasNoErrors();
        $this->cadastrar(['nome' => 'João Freela', 'email' => 'joao@sul.test', 'cpf' => '168.995.350-09', 'tipo_vinculo' => 'freelancer', 'cidade_base' => 'Porto Alegre'])->assertSessionHasNoErrors();
        $this->agencia->run(function () {
            $joao = User::where('email', 'joao@sul.test')->sole();
            $joao->forceFill(['aceite_privacidade_em' => now(), 'ativo' => false])->save();
        });

        $this->actingAs($this->admin)->get(self::URL.'/promotores')->assertOk()
            ->assertSee('Maria da Silva')->assertSee('João Freela')->assertSee('***.982.247-**')->assertDontSee('529.982.247-25')->assertDontSee('52998224725')
            ->assertSee('Convite pendente')->assertSee('Inativo');
        $this->get(self::URL.'/promotores?vinculo=freelancer')->assertOk()->assertSee('João Freela')->assertDontSee('Maria da Silva');
        $this->get(self::URL.'/promotores?situacao=convite')->assertOk()->assertSee('Maria da Silva')->assertDontSee('João Freela');
        $this->get(self::URL.'/promotores?situacao=inativo&busca=porto')->assertOk()->assertSee('João Freela');
        $this->get(self::URL.'/promotores?busca=inexistente')->assertOk()->assertSee('Nenhum promotor encontrado');
    }

    public function test_edicao_mostra_cpf_completo_registra_consulta_e_historico_sem_vazar_o_numero(): void
    {
        $this->cadastrar()->assertSessionHasNoErrors();
        $m = $this->maria();

        $this->actingAs($this->admin)->get(self::URL."/promotores/{$m->id}/editar")->assertOk()->assertSee('529.982.247-25')->assertSee('Histórico do cadastro')->assertSee('CPF consultado');
        // Consultas seguidas do mesmo admin não enchem o histórico.
        $this->get(self::URL."/promotores/{$m->id}/editar")->assertOk();
        $this->agencia->run(fn () => $this->assertSame(1, AuditoriaUsuario::where('acao', 'cpf_visualizado')->count()));

        $this->put(self::URL."/promotores/{$m->id}", $this->dados(['telefone' => '(54) 98888-1111', 'cpf' => '168.995.350-09', 'observacoes' => '']))->assertSessionHasNoErrors();
        $m = $this->maria();
        $this->assertSame('16899535009', $m->cpf);
        $this->assertSame('(54) 98888-1111', $m->telefone);
        $this->assertNull($m->observacoes);

        $this->agencia->run(function () use ($m) {
            $registros = AuditoriaUsuario::where('usuario_id', $m->id)->pluck('acao')->all();
            $this->assertEqualsCanonicalizing(['criado', 'cpf_visualizado', 'editado', 'cpf_alterado'], $registros);
            $editado = AuditoriaUsuario::where('acao', 'editado')->sole();
            $this->assertEqualsCanonicalizing(['telefone', 'observacoes'], $editado->detalhes['campos']);
            $this->assertSame($this->admin->id, $editado->autor_id);
            // Nada de CPF nos detalhes.
            $this->assertStringNotContainsString('16899535009', json_encode(AuditoriaUsuario::all()->toArray()));
        });

        // Em branco, mantém o CPF atual.
        $this->put(self::URL."/promotores/{$m->id}", $this->dados(['cpf' => '', 'nome' => 'Maria S. Silva']))->assertSessionHasNoErrors();
        $this->assertSame('16899535009', $this->maria()->cpf);
        $this->assertSame('Maria S. Silva', $this->maria()->nome);
    }

    public function test_convite_cria_a_senha_registra_o_aceite_e_so_vale_uma_vez(): void
    {
        $this->cadastrar()->assertSessionHasNoErrors();
        $link = session('convite_url');
        $m = $this->maria();

        $this->get($link)->assertOk()->assertSee('Aviso de privacidade')->assertSee('Maria')->assertHeader('Referrer-Policy', 'no-referrer');
        // Sem aceitar o aviso ou com senha fraca, nada muda.
        $this->post($link, ['password' => 'senha1234', 'password_confirmation' => 'senha1234'])->assertSessionHasErrors('aceite');
        $this->post($link, ['password' => 'abc', 'password_confirmation' => 'abc', 'aceite' => '1'])->assertSessionHasErrors('password');
        $this->assertNull($this->maria()->aceite_privacidade_em);

        $this->post($link, ['password' => 'senha1234', 'password_confirmation' => 'senha1234', 'aceite' => '1'])->assertRedirect(self::URL.'/convite');
        $this->get(self::URL.'/convite')->assertOk()->assertSee('sul')->assertSee('Tudo certo');
        $this->assertNotNull($this->maria()->aceite_privacidade_em);
        $this->agencia->run(fn () => $this->assertNotNull(Convite::sole()->usado_em));

        // O link não serve de novo.
        $this->get($link)->assertStatus(410)->assertSee('Link inválido ou vencido');
        $this->post($link, ['password' => 'outra1234', 'password_confirmation' => 'outra1234', 'aceite' => '1'])->assertStatus(410);

        // Agora ele entra no app com a senha que criou.
        $this->postJson(self::URL.'/api/v1/login', ['email' => 'maria@sul.test', 'password' => 'senha1234', 'dispositivo' => 'Teste'])->assertOk()->assertJsonPath('usuario.perfil', 'promotor');
        $this->agencia->run(fn () => $this->assertTrue(AuditoriaUsuario::where('acao', 'convite_aceito')->whereNull('autor_id')->exists()));
    }

    public function test_antes_do_convite_nao_ha_senha_utilizavel_e_convite_vencido_ou_inventado_e_recusado(): void
    {
        $this->cadastrar()->assertSessionHasNoErrors();
        $link = session('convite_url');
        $this->postJson(self::URL.'/api/v1/login', ['email' => 'maria@sul.test', 'password' => 'senha1234', 'dispositivo' => 'Teste'])->assertUnprocessable();

        $this->agencia->run(fn () => Convite::sole()->update(['expira_em' => now()->subMinute()]));
        $this->get($link)->assertStatus(410);
        $this->get(self::URL.'/convite/'.str_repeat('a', 48))->assertStatus(410);
        $this->get(self::URL.'/convite/curto')->assertNotFound();
    }

    public function test_novo_convite_invalida_o_anterior_e_serve_para_redefinir_a_senha(): void
    {
        $this->cadastrar()->assertSessionHasNoErrors();
        $primeiro = session('convite_url');
        $m = $this->maria();
        $this->post($primeiro, ['password' => 'senha1234', 'password_confirmation' => 'senha1234', 'aceite' => '1'])->assertRedirect();

        $this->actingAs($this->admin)->post(self::URL."/promotores/{$m->id}/convite")->assertRedirect()->assertSessionHas('convite_url');
        $novo = session('convite_url');
        $this->assertNotSame($primeiro, $novo);
        $this->get($novo)->assertOk();

        $this->post($novo, ['password' => 'trocada1234', 'password_confirmation' => 'trocada1234', 'aceite' => '1'])->assertRedirect();
        $this->postJson(self::URL.'/api/v1/login', ['email' => 'maria@sul.test', 'password' => 'senha1234', 'dispositivo' => 'T'])->assertUnprocessable();
        $this->postJson(self::URL.'/api/v1/login', ['email' => 'maria@sul.test', 'password' => 'trocada1234', 'dispositivo' => 'T'])->assertOk();

        // Gerar outro convite derruba o anterior que ainda estava aberto.
        $this->actingAs($this->admin)->post(self::URL."/promotores/{$m->id}/convite");
        $a = session('convite_url');
        $this->post(self::URL."/promotores/{$m->id}/convite");
        $this->get($a)->assertStatus(410);
    }

    public function test_desativar_derruba_o_app_cancela_convites_e_reativar_volta(): void
    {
        $this->cadastrar()->assertSessionHasNoErrors();
        $link = session('convite_url');
        $m = $this->maria();
        $this->post($link, ['password' => 'senha1234', 'password_confirmation' => 'senha1234', 'aceite' => '1']);
        $token = $this->postJson(self::URL.'/api/v1/login', ['email' => 'maria@sul.test', 'password' => 'senha1234', 'dispositivo' => 'Moto G'])->assertOk()->json('token');
        $this->agencia->run(fn () => $this->assertSame(1, $m->tokens()->count()));

        $this->actingAs($this->admin)->patch(self::URL."/promotores/{$m->id}/ativo", ['ativo' => 0])->assertRedirect();
        $this->agencia->run(fn () => $this->assertSame(0, $m->tokens()->count(), 'aparelho desconectado'));
        $this->assertFalse($this->maria()->ativo);
        $this->postJson(self::URL.'/api/v1/login', ['email' => 'maria@sul.test', 'password' => 'senha1234', 'dispositivo' => 'Moto G'])->assertUnprocessable();
        $this->post(self::URL."/promotores/{$m->id}/convite")->assertStatus(409);

        $this->patch(self::URL."/promotores/{$m->id}/ativo", ['ativo' => 1])->assertRedirect();
        $this->postJson(self::URL.'/api/v1/login', ['email' => 'maria@sul.test', 'password' => 'senha1234', 'dispositivo' => 'Moto G'])->assertOk();
        $this->agencia->run(fn () => $this->assertEqualsCanonicalizing(['criado', 'convite_aceito', 'desativado', 'ativado'], AuditoriaUsuario::where('usuario_id', $m->id)->pluck('acao')->all()));
        $this->assertNotEmpty($token);
    }

    public function test_foto_so_para_a_gestao_e_supervisor_consulta_sem_cadastrar(): void
    {
        $this->cadastrar([], UploadedFile::fake()->image('rosto.jpg', 300, 300))->assertSessionHasNoErrors();
        $m = $this->maria();
        $agenciaId = $this->agencia->run(fn () => Agencia::first()->id);
        $supervisor = $this->criarUsuario($this->agencia, Perfil::Supervisor, ['agencia_id' => $agenciaId]);
        $promotor = $this->criarUsuario($this->agencia, Perfil::Promotor, ['email' => 'outro@sul.test', 'agencia_id' => $agenciaId, 'tipo_vinculo' => 'clt']);

        $this->app['auth']->forgetGuards();
        $this->get(self::URL."/promotores/{$m->id}/foto")->assertRedirect(); // sem login
        $this->actingAs($this->admin)->get(self::URL."/promotores/{$m->id}/foto")->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $this->actingAs($supervisor)->get(self::URL.'/promotores')->assertOk()->assertSee('Maria da Silva')->assertDontSee('Novo promotor')->assertDontSee('Abrir</a>', false);
        $this->get(self::URL."/promotores/{$m->id}/foto")->assertOk();
        $this->get(self::URL.'/promotores/novo')->assertForbidden();
        $this->post(self::URL.'/promotores', $this->dados(['email' => 'x@sul.test', 'cpf' => '168.995.350-09']))->assertForbidden();
        $this->get(self::URL."/promotores/{$m->id}/editar")->assertForbidden();
        $this->patch(self::URL."/promotores/{$m->id}/ativo", ['ativo' => 0])->assertForbidden();
        $this->post(self::URL."/promotores/{$m->id}/convite")->assertForbidden();

        $this->actingAs($promotor)->get(self::URL.'/promotores')->assertForbidden();
        $this->get(self::URL."/promotores/{$m->id}/foto")->assertForbidden();
    }

    public function test_promotor_sai_da_tela_de_usuarios_e_a_indústria_não_tem_a_tela(): void
    {
        $this->cadastrar()->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get(self::URL.'/usuarios')->assertOk()->assertDontSee('Maria da Silva')->assertDontSee('>Promotor<', false);
        $this->get(self::URL.'/painel')->assertOk()->assertSee('Promotores');

        tenancy()->end();
        $industria = $this->criarCliente('gaboardi');
        $adminInd = $industria->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
        $this->actingAs($adminInd)->get('http://gaboardi.test/promotores')->assertForbidden();
        $this->get('http://gaboardi.test/painel')->assertOk()->assertDontSee('>Promotores<', false);
    }

    private function planilha(array $linhas): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'prom').'.xlsx';
        $w = new Writer;
        $w->openToFile($caminho);
        foreach ($linhas as $l) {
            $w->addRow(Row::fromValues($l));
        }
        $w->close();

        return new UploadedFile($caminho, 'promotores.xlsx', null, null, true);
    }

    public function test_importacao_cria_os_validos_com_convite_e_relata_os_erros(): void
    {
        $this->cadastrar(['nome' => 'Já Existe', 'email' => 'existe@sul.test', 'cpf' => '168.995.350-09'])->assertSessionHasNoErrors();

        $arquivo = $this->planilha([
            ['Nome', 'E-mail', 'CPF', 'Telefone', 'Vínculo', 'Cidade base', 'UF'],
            ['Ana Lima', 'ana@sul.test', '529.982.247-25', '(54) 91111-1111', 'CLT', 'Caxias do Sul', 'rs'],
            ['Bruno Souza', 'bruno@sul.test', 11144477735, '', 'Freelancer', 'Porto Alegre', 'RS'],
            ['Sem CPF', 'semcpf@sul.test', '', '', '', '', ''],
            ['CPF Ruim', 'ruim@sul.test', '123.456.789-00', '', '', '', ''],
            ['Email Repetido', 'existe@sul.test', '123.456.789-09', '', '', '', ''],
            ['Cpf Repetido', 'repetido@sul.test', '168.995.350-09', '', '', '', ''],
            ['Duplicado na planilha', 'ana@sul.test', '111.444.777-35', '', '', '', ''],
            ['Vínculo errado', 'v@sul.test', '987.654.321-00', '', 'Estagiário', '', ''],
        ]);
        $this->actingAs($this->admin)->post(self::URL.'/promotores/importar', ['arquivo' => $arquivo])->assertRedirect(self::URL.'/promotores/importar/resultado');

        $this->get(self::URL.'/promotores/importar/resultado')->assertOk()
            ->assertSee('2')->assertSee('Ana Lima')->assertSee('Bruno Souza')->assertSee('convite/', false)
            ->assertSee('CPF já cadastrado.')->assertSee('E-mail já cadastrado.')->assertSee('E-mail repetido na planilha.')->assertSee('Vínculo deve ser CLT ou Freelancer.');

        $this->agencia->run(function () {
            $this->assertSame(['ana@sul.test', 'bruno@sul.test', 'existe@sul.test'], User::where('perfil', 'promotor')->orderBy('email')->pluck('email')->all());
            $ana = User::where('email', 'ana@sul.test')->sole();
            $this->assertSame('RS', $ana->uf_base);
            $this->assertSame('freelancer', User::where('email', 'bruno@sul.test')->sole()->tipo_vinculo->value);
            $this->assertSame('11144477735', User::where('email', 'bruno@sul.test')->sole()->cpf, 'número do Excel');
            $this->assertSame(3, Convite::count());
            $this->assertSame(2, AuditoriaUsuario::where('acao', 'importado')->count());
        });

        // Planilha com os links, só logo depois da importação.
        $csv = $this->get(self::URL.'/promotores/importar/links')->assertOk()->streamedContent();
        $this->assertStringContainsString('ana@sul.test;http://sul.test/convite/', $csv);
        $this->assertSame(2, substr_count($csv, 'http://sul.test/convite/'));

        // Reimportar a mesma planilha não duplica ninguém.
        $this->post(self::URL.'/promotores/importar', ['arquivo' => $this->planilha([
            ['Nome', 'E-mail', 'CPF'], ['Ana Lima', 'ana@sul.test', '529.982.247-25'],
        ])])->assertRedirect();
        $this->get(self::URL.'/promotores/importar/resultado')->assertOk()->assertSee('E-mail já cadastrado.');
        $this->agencia->run(fn () => $this->assertSame(3, User::where('perfil', 'promotor')->count()));
    }

    public function test_importacao_recusa_planilha_sem_colunas_obrigatorias_ou_vazia_e_oferece_o_modelo(): void
    {
        $this->actingAs($this->admin)->post(self::URL.'/promotores/importar', ['arquivo' => $this->planilha([['Nome', 'Telefone'], ['A', '1']])])->assertSessionHasErrors('arquivo');
        $this->post(self::URL.'/promotores/importar', ['arquivo' => $this->planilha([['Nome', 'E-mail', 'CPF']])])->assertSessionHasErrors('arquivo');
        $this->post(self::URL.'/promotores/importar', ['arquivo' => UploadedFile::fake()->create('x.pdf', 5)])->assertSessionHasErrors('arquivo');
        $this->get(self::URL.'/promotores/importar/resultado')->assertRedirect(self::URL.'/promotores/importar');
        $this->get(self::URL.'/promotores/importar/links')->assertNotFound();
        $this->get(self::URL.'/promotores/importar/modelo')->assertOk()->assertDownload('modelo-promotores.xlsx');
        $this->get(self::URL.'/promotores/importar')->assertOk()->assertSee('Baixar modelo');
    }

    public function test_importacao_aceita_csv_com_ponto_e_virgula_e_acentos_do_excel(): void
    {
        $csv = "Nome;E-mail;CPF;Vínculo\r\nJosé Conceição;jose@sul.test;529.982.247-25;freelancer\r\n";
        $arquivo = UploadedFile::fake()->createWithContent('promotores.csv', mb_convert_encoding($csv, 'Windows-1252', 'UTF-8'));

        $this->actingAs($this->admin)->post(self::URL.'/promotores/importar', ['arquivo' => $arquivo])->assertRedirect();
        $this->agencia->run(function () {
            $u = User::where('email', 'jose@sul.test')->sole();
            $this->assertSame('José Conceição', $u->nome);
            $this->assertSame('freelancer', $u->tipo_vinculo->value);
        });
    }
}
