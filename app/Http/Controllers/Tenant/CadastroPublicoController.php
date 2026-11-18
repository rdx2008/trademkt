<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\TipoInstalacao;
use App\Http\Controllers\Controller;
use App\Models\Configuracao;
use App\Models\User;
use App\Rules\Cpf;
use App\Services\Promotores\Cadastro;
use App\Support\Estados;
use App\Support\Municipios;
use App\Support\TermoDaRede;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Cadastro do promotor pelo link da agência (página pública, sem login). A agência manda o link
 * e pode ligar e desligar o cadastro; quem se cadastra não precisa de aceite da agência.
 */
class CadastroPublicoController extends Controller
{
    public const CHAVE_ATIVO = 'cadastro_link_ativo';

    public function __construct(private Cadastro $cadastro) {}

    public static function ativo(): bool
    {
        return (Configuracao::todas()[self::CHAVE_ATIVO] ?? '0') === '1';
    }

    /** Só vale em instalação de agência, com o código dela no endereço e o cadastro ligado. */
    private function disponivel(string $codigo): bool
    {
        return tenant()->tipo === TipoInstalacao::Agencia && $codigo === (string) tenant('codigo') && self::ativo();
    }

    public function mostrar(string $codigo): View|Response
    {
        if (! $this->disponivel($codigo)) {
            return $this->indisponivel();
        }

        return response()->view('tenant.cadastro.form', ['codigo' => $codigo, 'ufs' => Estados::NOMES, 'termo' => TermoDaRede::texto()])->withHeaders($this->cabecalhos());
    }

    public function enviar(Request $request, string $codigo): RedirectResponse|Response
    {
        if (! $this->disponivel($codigo)) {
            return $this->indisponivel();
        }
        $request->merge(['email' => mb_strtolower((string) $request->input('email'))]);
        $cpfLimpo = Cpf::limpar((string) $request->input('cpf'));
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:120'],
            'cpf' => ['required', new Cpf, function ($atributo, $valor, $falhar) use ($cpfLimpo) {
                if ($cpfLimpo !== '' && User::where('cpf_hash', Cpf::hash($cpfLimpo))->exists()) {
                    $falhar('Este CPF já está cadastrado. Peça um novo link de acesso à sua agência.');
                }
            }],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'telefone' => ['required', 'string', 'max:20'],
            'uf_base' => ['required', Rule::in(Estados::UFS)],
            'municipio_ibge' => ['required', 'digits:7', function ($atributo, $valor, $falhar) use ($request) {
                if (! Municipios::pertenceAUf((string) $valor, (string) $request->input('uf_base'))) {
                    $falhar('Escolha a cidade na lista, dentro da UF informada.');
                }
            }],
            'raio_km' => ['nullable', 'integer', 'between:5,200'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'aceite' => ['accepted'],
        ], [
            'aceite.accepted' => 'Marque que aceita os termos de uso compartilhado da rede da plataforma para continuar.',
            'email.unique' => 'Este e-mail já está cadastrado.',
            'foto.image' => 'Envie uma foto em JPG, PNG ou WebP.',
            'foto.max' => 'A foto pode ter até 3 MB.',
        ], ['cpf' => 'CPF', 'uf_base' => 'UF', 'password' => 'senha', 'municipio_ibge' => 'cidade', 'raio_km' => 'raio de atuação', 'telefone' => 'telefone']);

        $this->cadastro->autocadastrar(
            collect($dados)->only(['nome', 'cpf', 'email', 'telefone', 'uf_base', 'municipio_ibge', 'raio_km'])->all(),
            $request->file('foto'),
            $dados['password'],
            $request->ip(),
            $request->userAgent(),
        );

        return redirect()->route('convite.pronto')->with('convite_painel', false)->withHeaders($this->cabecalhos());
    }

    private function indisponivel(): Response
    {
        return response()->view('tenant.cadastro.indisponivel', [], 404)->withHeaders($this->cabecalhos());
    }

    /** @return array<string, string> */
    private function cabecalhos(): array
    {
        return ['Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store, private'];
    }
}
