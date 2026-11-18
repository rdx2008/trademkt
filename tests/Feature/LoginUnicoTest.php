<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\Cpf;
use App\Services\Promotores\Cadastro;
use App\Services\Promotores\IdentidadeGlobal;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Login único do app: e-mail e senha, sem código da agência; uma conta por agência onde a senha confere. */
class LoginUnicoTest extends TestCase
{
    private const CENTRAL = 'http://central.test/api/v1/entrar';

    private Tenant $sul;

    private Tenant $norte;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sul = $this->criarCliente('sul', 'agencia');
        $this->norte = $this->criarCliente('norte', 'agencia');
        tenancy()->end();
    }

    private function promotor(Tenant $agencia, string $email, string $senha, string $cpf = '52998224725', bool $ativo = true): User
    {
        return $agencia->run(function () use ($email, $senha, $cpf, $ativo) {
            $u = User::create([
                'nome' => 'Joana Souza', 'email' => $email, 'password' => $senha, 'perfil' => Perfil::Promotor, 'ativo' => $ativo,
                'cpf' => $cpf, 'cpf_hash' => Cpf::hash($cpf), 'tipo_vinculo' => 'freelancer',
            ]);
            app(IdentidadeGlobal::class)->vincular($u);

            return $u;
        });
    }

    private function entrar(string $email, string $senha, string $aparelho = 'Moto G'): TestResponse
    {
        return $this->postJson(self::CENTRAL, ['email' => $email, 'password' => $senha, 'dispositivo' => $aparelho]);
    }

    public function test_entra_so_com_email_e_senha_e_o_token_funciona_na_agencia(): void
    {
        $this->promotor($this->sul, 'joana@exemplo.com', 'Senha12345');

        $r = $this->entrar('Joana@Exemplo.com', 'Senha12345')->assertOk();
        $this->assertCount(1, $r->json('contas'));
        $conta = $r->json('contas.0');
        $this->assertSame('sul', $conta['empresa']['codigo']);
        $this->assertSame('http://sul.test/api/v1', $conta['empresa']['api']);
        $this->assertArrayHasKey('marca', $conta['empresa']);
        $this->assertSame('joana@exemplo.com', $conta['usuario']['email']);
        $this->assertSame('freelancer', $conta['usuario']['tipo_vinculo']);
        $this->assertStringStartsWith('http://sul.test/verificar/', $conta['usuario']['cracha_url']);

        // O token emitido pelo login único vale na API da própria agência.
        $this->withHeader('Authorization', 'Bearer '.$conta['token'])->getJson('http://sul.test/api/v1/me')->assertOk()->assertJsonPath('usuario.email', 'joana@exemplo.com');
    }

    public function test_entra_em_todas_as_agencias_onde_a_senha_confere_e_so_nelas(): void
    {
        $this->promotor($this->sul, 'joana@exemplo.com', 'Senha12345');
        $this->promotor($this->norte, 'joana@exemplo.com', 'Senha12345');

        $this->assertSame(['norte', 'sul'], collect($this->entrar('joana@exemplo.com', 'Senha12345')->assertOk()->json('contas'))->pluck('empresa.codigo')->sort()->values()->all());

        // Senha diferente em uma das agências: entra só na outra, sem contar o motivo.
        $this->norte->run(fn () => User::where('email', 'joana@exemplo.com')->update(['password' => bcrypt('OutraSenha99')]));
        $contas = $this->entrar('joana@exemplo.com', 'Senha12345')->assertOk()->json('contas');
        $this->assertSame(['sul'], collect($contas)->pluck('empresa.codigo')->all());
    }

    public function test_senha_errada_email_desconhecido_e_promotor_inativo_dao_o_mesmo_erro(): void
    {
        $this->promotor($this->sul, 'joana@exemplo.com', 'Senha12345');
        $this->promotor($this->norte, 'inativa@exemplo.com', 'Senha12345', '11144477735', false);

        foreach ([['joana@exemplo.com', 'errada123'], ['ninguem@exemplo.com', 'Senha12345'], ['inativa@exemplo.com', 'Senha12345']] as [$email, $senha]) {
            $this->entrar($email, $senha)->assertStatus(422)->assertJsonPath('errors.email.0', 'E-mail ou senha inválidos.');
        }
    }

    public function test_agencia_suspensa_ou_desativacao_posterior_tira_o_acesso(): void
    {
        $u = $this->promotor($this->sul, 'joana@exemplo.com', 'Senha12345');
        $this->sul->update(['ativo' => false]);
        $this->entrar('joana@exemplo.com', 'Senha12345')->assertStatus(422);

        $this->sul->update(['ativo' => true]);
        $this->entrar('joana@exemplo.com', 'Senha12345')->assertOk();

        // Desativar o promotor na agência reflete no índice central.
        $this->sul->run(fn () => app(Cadastro::class)->alternarAtivo($u->fresh(), false, null));
        tenancy()->end();
        $this->entrar('joana@exemplo.com', 'Senha12345')->assertStatus(422);
    }

    public function test_mudar_o_email_na_agencia_atualiza_o_indice(): void
    {
        $u = $this->promotor($this->sul, 'antigo@exemplo.com', 'Senha12345');
        $this->sul->run(function () use ($u) {
            $u->forceFill(['email' => 'novo@exemplo.com'])->save();
            app(IdentidadeGlobal::class)->vincular($u->fresh());
        });
        tenancy()->end();

        $this->entrar('antigo@exemplo.com', 'Senha12345')->assertStatus(422);
        $this->entrar('novo@exemplo.com', 'Senha12345')->assertOk();
        $this->assertSame(1, DB::connection(config('tenancy.database.central_connection'))->table('promotor_agencias')->count());
    }

    public function test_token_e_um_por_aparelho_e_o_login_da_agencia_continua_igual(): void
    {
        $this->promotor($this->sul, 'joana@exemplo.com', 'Senha12345');
        $primeiro = $this->entrar('joana@exemplo.com', 'Senha12345', 'Moto G')->json('contas.0.token');
        $segundo = $this->entrar('joana@exemplo.com', 'Senha12345', 'Moto G')->json('contas.0.token');
        $this->assertNotSame($primeiro, $segundo);
        $this->assertSame(1, $this->sul->run(fn () => User::where('email', 'joana@exemplo.com')->sole()->tokens()->count()));

        // O login pelo código da agência segue funcionando para quem não atualizou o app.
        $this->postJson('http://sul.test/api/v1/login', ['email' => 'joana@exemplo.com', 'password' => 'Senha12345', 'dispositivo' => 'Outro'])
            ->assertOk()->assertJsonPath('usuario.email', 'joana@exemplo.com');
    }

    public function test_valida_os_campos_e_limita_tentativas(): void
    {
        $this->entrar('nao-e-email', 'x')->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson(self::CENTRAL, ['email' => 'a@b.com', 'password' => 'x'])->assertStatus(422)->assertJsonValidationErrors('dispositivo');

        for ($i = 0; $i < 5; $i++) {
            $this->entrar('alvo@exemplo.com', 'errada'.$i)->assertStatus(422);
        }
        $this->entrar('alvo@exemplo.com', 'errada9')->assertStatus(429);
    }
}
