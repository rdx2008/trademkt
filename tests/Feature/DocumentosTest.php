<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\Documento;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Fase 10 (10.9b): meus documentos, com permissão por perfil. */
class DocumentosTest extends TestCase
{
    private const URL = 'http://xyz.test';

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->tenant = $this->criarCliente('xyz', 'agencia');
        $this->admin = $this->tenant->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->sole());
    }

    private function enviar(array $extra = [], ?UploadedFile $arquivo = null)
    {
        return $this->post(self::URL.'/documentos', $extra + [
            'titulo' => 'Planograma de verão', 'categoria' => 'planograma',
            'arquivo' => $arquivo ?? UploadedFile::fake()->create('planograma.pdf', 120, 'application/pdf'),
        ]);
    }

    private function como(Perfil $perfil, string $email): User
    {
        $this->app['auth']->forgetGuards();
        $u = $this->criarUsuario($this->tenant, $perfil, ['email' => $email, 'agencia_id' => $this->tenant->run(fn () => Agencia::first()->id)]);
        $this->actingAs($u);

        return $u;
    }

    public function test_admin_envia_e_baixa_e_o_arquivo_fica_privado(): void
    {
        $this->actingAs($this->admin)->get(self::URL.'/documentos')->assertOk()->assertSee('Enviar documento');
        $this->enviar(['descricao' => 'Versão 2'])->assertSessionHasNoErrors()->assertRedirect(self::URL.'/documentos');

        $d = $this->tenant->run(fn () => Documento::sole());
        $this->assertSame('planograma.pdf', $d->nome_original);
        $this->assertStringStartsWith('documentos/', $d->arquivo);
        $this->assertTrue(Storage::disk('local')->exists($d->arquivo));
        $this->assertNotSame('planograma.pdf', basename($d->arquivo), 'o nome gravado não é o do usuário');

        $this->get(self::URL.'/documentos')->assertSee('Planograma de verão')->assertSee('Versão 2');
        $r = $this->get(self::URL."/documentos/{$d->id}/baixar")->assertOk();
        $this->assertStringContainsString('planograma.pdf', (string) $r->headers->get('Content-Disposition'));
    }

    public function test_visibilidade_por_perfil(): void
    {
        $this->actingAs($this->admin);
        $this->enviar(['titulo' => 'Padrão'])->assertSessionHasNoErrors();
        $this->enviar(['titulo' => 'Só promotor', 'perfis' => ['promotor']])->assertSessionHasNoErrors();
        [$padrao, $promotor] = $this->tenant->run(fn () => [Documento::where('titulo', 'Padrão')->value('id'), Documento::where('titulo', 'Só promotor')->value('id')]);

        $this->como(Perfil::Supervisor, 'sup@xyz.test');
        $this->get(self::URL.'/documentos')->assertOk()->assertSee('Padrão')->assertDontSee('Só promotor');
        $this->get(self::URL."/documentos/{$padrao}/baixar")->assertOk();
        $this->get(self::URL."/documentos/{$promotor}/baixar")->assertNotFound();

        $this->como(Perfil::Promotor, 'p@xyz.test');
        $this->get(self::URL.'/documentos')->assertOk()->assertSee('Só promotor')->assertDontSee('Padrão');
        $this->get(self::URL."/documentos/{$promotor}/baixar")->assertOk();
        $this->get(self::URL."/documentos/{$padrao}/baixar")->assertNotFound();
    }

    public function test_so_o_admin_envia_e_remove_e_o_tipo_e_validado(): void
    {
        $this->como(Perfil::Supervisor, 'sup@xyz.test');
        $this->enviar()->assertForbidden();
        $this->get(self::URL.'/documentos')->assertOk()->assertDontSee('Enviar documento');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin);
        $this->enviar([], UploadedFile::fake()->create('virus.exe', 10))->assertSessionHasErrors('arquivo');
        $this->enviar([], UploadedFile::fake()->create('script.php', 10))->assertSessionHasErrors('arquivo');
        $this->enviar([], UploadedFile::fake()->create('grande.pdf', 25000))->assertSessionHasErrors('arquivo');
        $this->enviar(['perfis' => ['inventado']])->assertSessionHasErrors('perfis.0');
        $this->assertSame(0, $this->tenant->run(fn () => Documento::count()));

        $this->enviar()->assertSessionHasNoErrors();
        $d = $this->tenant->run(fn () => Documento::sole());
        $this->como(Perfil::Supervisor, 'sup2@xyz.test');
        $this->delete(self::URL."/documentos/{$d->id}")->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)->delete(self::URL."/documentos/{$d->id}")->assertRedirect();
        $this->assertSame(0, $this->tenant->run(fn () => Documento::count()));
        $this->assertFalse(Storage::disk('local')->exists($d->arquivo));
    }
}
