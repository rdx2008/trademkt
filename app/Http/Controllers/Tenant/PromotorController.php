<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Enums\TipoVinculo;
use App\Http\Controllers\Controller;
use App\Models\Afastamento;
use App\Models\User;
use App\Rules\Cpf;
use App\Services\Promotores\Cadastro;
use App\Services\Promotores\FichaOperacional;
use App\Services\Promotores\ImportacaoPromotores;
use App\Services\Promotores\Redistribuicao;
use App\Support\Estados;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Laravel\Sanctum\PersonalAccessToken;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controle de promotores da agência: a equipe de campo, separada dos usuários do painel. Quem
 * entra no painel (admin e supervisor) consulta; só o admin cadastra, edita e desativa.
 */
class PromotorController extends Controller
{
    public function __construct(private Cadastro $cadastro) {}

    private function consultar(Request $request): User
    {
        $eu = $request->user();
        abort_unless(tenant()->tipo === TipoInstalacao::Agencia && $eu->podeVerPromotores(), 403);

        return $eu;
    }

    private function gerir(Request $request): User
    {
        $eu = $this->consultar($request);
        abort_unless($eu->podeGerirPromotores(), 403);

        return $eu;
    }

    private function promotor(int $id): User
    {
        return User::where('perfil', Perfil::Promotor->value)->findOrFail($id);
    }

    public function index(Request $request): View
    {
        $eu = $this->consultar($request);
        $filtros = $request->validate([
            'busca' => ['nullable', 'string', 'max:100'],
            'vinculo' => ['nullable', Rule::enum(TipoVinculo::class)],
            'situacao' => ['nullable', Rule::in(['ativo', 'inativo', 'convite'])],
        ]);
        $promotores = User::where('perfil', Perfil::Promotor->value)
            ->when($filtros['busca'] ?? null, function ($q, $busca) {
                $like = '%'.$busca.'%';
                $q->where(fn ($s) => $s->where('nome', 'like', $like)->orWhere('email', 'like', $like)->orWhere('telefone', 'like', $like)->orWhere('cidade_base', 'like', $like));
            })
            ->when($filtros['vinculo'] ?? null, fn ($q, $v) => $q->where('tipo_vinculo', $v))
            ->when($filtros['situacao'] ?? null, fn ($q, $s) => match ($s) {
                'ativo' => $q->where('ativo', true)->whereNotNull('aceite_privacidade_em'),
                'inativo' => $q->where('ativo', false),
                'convite' => $q->where('ativo', true)->whereNull('aceite_privacidade_em'),
            })
            ->orderBy('nome')
            ->paginate(30)
            ->withQueryString();

        return view('tenant.promotores.index', [
            'promotores' => $promotores,
            'filtros' => $filtros,
            'podeGerir' => $eu->podeGerirPromotores(),
            'afastados' => Afastamento::em(now()->toDateString())->pluck('tipo', 'usuario_id'),
            'hoje' => app(FichaOperacional::class)->resumoDeHoje(tenant(), $promotores->pluck('id')->all()),
            'aparelhos' => PersonalAccessToken::whereIn('tokenable_id', $promotores->pluck('id'))->where('tokenable_type', (new User)->getMorphClass())
                ->orderBy('last_used_at')->get(['tokenable_id', 'name', 'last_used_at'])->keyBy('tokenable_id'),
            'semIndustria' => $promotores->filter(fn (User $u) => $u->habilitacao_restrita)->pluck('id')
                ->diff(DB::table('promotor_industrias')->whereIn('usuario_id', $promotores->pluck('id'))->pluck('usuario_id'))->flip(),
            'vinculos' => TipoVinculo::cases(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->gerir($request);

        return view('tenant.promotores.form', $this->dadosFormulario(new User(['ativo' => true, 'tipo_vinculo' => 'clt'])));
    }

    public function store(Request $request): RedirectResponse
    {
        $eu = $this->gerir($request);
        $dados = $this->validar($request);
        $criado = $this->cadastro->criar($dados, $request->file('foto'), $eu);

        return redirect()->route('promotores.edit', $criado['usuario'])
            ->with('status', 'Promotor cadastrado. Envie o link abaixo para ele criar a própria senha.')
            ->with('convite_url', $criado['convite']);
    }

    public function edit(Request $request, int $promotor): View
    {
        $eu = $this->gerir($request);
        $usuario = $this->promotor($promotor);
        $this->cadastro->registrarConsultaDoCpf($usuario, $eu);

        return view('tenant.promotores.form', $this->dadosFormulario($usuario) + [
            'historico' => $usuario->auditoria()->limit(30)->get(),
            'autores' => User::whereIn('id', $usuario->auditoria()->limit(30)->pluck('autor_id')->filter())->pluck('nome', 'id'),
        ]);
    }

    public function update(Request $request, int $promotor): RedirectResponse
    {
        $eu = $this->gerir($request);
        $usuario = $this->promotor($promotor);
        $dados = $this->validar($request, $usuario);
        $this->cadastro->atualizar($usuario, $dados, $request->file('foto'), $eu);

        return redirect()->route('promotores.edit', $usuario)->with('status', 'Cadastro atualizado.');
    }

    public function ativo(Request $request, int $promotor): RedirectResponse
    {
        $eu = $this->gerir($request);
        $usuario = $this->promotor($promotor);
        $ativar = $request->boolean('ativo');
        $this->cadastro->alternarAtivo($usuario, $ativar, $eu);

        $resposta = redirect()->route('promotores.edit', $usuario)->with('status', $ativar ? 'Promotor reativado.' : 'Promotor desativado. O app dele foi desconectado.');
        if (! $ativar) {
            $inicio = today()->toDateString();
            $fim = today()->addDays(60)->toDateString();
            $pendentes = count(app(Redistribuicao::class)->executar(tenant(), $usuario, $inicio, $fim, $eu));
            if ($pendentes > 0) {
                $resposta->with('redistribuir', ['n' => $pendentes, 'url' => route('promotores.redistribuir', ['promotor' => $usuario->id, 'inicio' => $inicio, 'fim' => $fim])]);
            }
        }

        return $resposta;
    }

    public function convite(Request $request, int $promotor): RedirectResponse
    {
        $eu = $this->gerir($request);
        $usuario = $this->promotor($promotor);
        abort_unless($usuario->ativo, 409, 'Reative o promotor antes de gerar um convite.');
        $link = $this->cadastro->gerarConvite($usuario, $eu);

        return redirect()->route('promotores.edit', $usuario)
            ->with('status', 'Novo convite gerado. Os links anteriores deixaram de valer.')
            ->with('convite_url', $link);
    }

    /** Foto do promotor, só para quem consulta a equipe. */
    public function foto(Request $request, int $promotor): StreamedResponse
    {
        $this->consultar($request);
        $usuario = $this->promotor($promotor);
        abort_unless($usuario->foto && Storage::disk('local')->exists($usuario->foto), 404);

        return Storage::disk('local')->response($usuario->foto, headers: ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    // -------------------------------------------------------------- importação

    public function importar(Request $request): View
    {
        $this->gerir($request);

        return view('tenant.promotores.importar');
    }

    public function importarEnviar(Request $request, ImportacaoPromotores $importacao): RedirectResponse
    {
        $eu = $this->gerir($request);
        $request->validate([
            'arquivo' => ['required', 'file', 'extensions:csv,txt,xlsx', 'max:2048'],
        ], ['arquivo.extensions' => 'Envie uma planilha CSV ou XLSX.']);
        $arquivo = $request->file('arquivo');
        $resultado = $importacao->importar($arquivo->getRealPath(), $arquivo->getClientOriginalExtension(), $eu);
        $request->session()->put('importacao_promotores', $resultado);

        return redirect()->route('promotores.importar.resultado');
    }

    public function importarResultado(Request $request): View|RedirectResponse
    {
        $this->gerir($request);
        $resultado = $request->session()->get('importacao_promotores');
        if (! $resultado) {
            return redirect()->route('promotores.importar');
        }

        return view('tenant.promotores.importacao-resultado', $resultado);
    }

    /** Planilha com os links de convite, para enviar a cada promotor. Só existe logo depois da importação. */
    public function importarLinks(Request $request): StreamedResponse
    {
        $this->gerir($request);
        $resultado = $request->session()->get('importacao_promotores');
        abort_unless($resultado, 404);

        return response()->streamDownload(function () use ($resultado) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, ['Nome', 'E-mail', 'Link do convite'], ';');
            foreach ($resultado['criados'] as $c) {
                fputcsv($f, [$c['nome'], $c['email'], $c['link']], ';');
            }
            fclose($f);
        }, 'convites-promotores.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function modelo(Request $request): BinaryFileResponse
    {
        $this->gerir($request);
        $caminho = tempnam(sys_get_temp_dir(), 'modelo-promotores').'.xlsx';
        $escritor = new Writer;
        $escritor->openToFile($caminho);
        $escritor->addRow(Row::fromValues(['Nome', 'E-mail', 'CPF', 'Telefone', 'Vínculo', 'Cidade base', 'UF', 'Observações']));
        $escritor->addRow(Row::fromValues(['Maria da Silva', 'maria@exemplo.com.br', '529.982.247-25', '(54) 99999-0000', 'CLT', 'Caxias do Sul', 'RS', '']));
        $escritor->addRow(Row::fromValues(['João Pereira', 'joao@exemplo.com.br', '52998224725', '(51) 98888-0000', 'Freelancer', 'Porto Alegre', 'RS', 'Atende aos sábados']));
        $escritor->close();

        return response()->download($caminho, 'modelo-promotores.xlsx')->deleteFileAfterSend();
    }

    // ------------------------------------------------------------------ apoio

    /** @return array<string, mixed> */
    private function validar(Request $request, ?User $usuario = null): array
    {
        $request->merge(['email' => mb_strtolower((string) $request->input('email'))]);
        $cpfNovo = Cpf::limpar((string) $request->input('cpf'));
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($usuario?->id)],
            'cpf' => [$usuario?->cpf ? 'nullable' : 'required', new Cpf, function ($atributo, $valor, $falhar) use ($usuario, $cpfNovo) {
                if ($cpfNovo !== '' && User::where('cpf_hash', Cpf::hash($cpfNovo))->when($usuario, fn ($q) => $q->whereKeyNot($usuario->id))->exists()) {
                    $falhar('Já existe um promotor com este CPF.');
                }
            }],
            'telefone' => ['nullable', 'string', 'max:20'],
            'tipo_vinculo' => ['required', Rule::enum(TipoVinculo::class)],
            'cidade_base' => ['nullable', 'string', 'max:80'],
            'uf_base' => ['nullable', Rule::in(Estados::UFS)],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ], [
            'cpf.required' => 'Informe o CPF do promotor.',
            'tipo_vinculo.required' => 'Informe se o promotor é CLT ou freelancer.',
            'foto.image' => 'Envie uma foto em JPG, PNG ou WebP.',
            'foto.max' => 'A foto pode ter até 3 MB.',
        ], ['cpf' => 'CPF', 'uf_base' => 'UF']);
        // Edição com o campo de CPF em branco mantém o CPF atual.
        if (($dados['cpf'] ?? '') === '' || ($dados['cpf'] ?? null) === null) {
            unset($dados['cpf']);
        }
        unset($dados['foto']);

        return $dados;
    }

    /** @return array<string, mixed> */
    private function dadosFormulario(User $usuario): array
    {
        return [
            'usuario' => $usuario,
            'vinculos' => TipoVinculo::cases(),
            'ufs' => Estados::NOMES,
            'cpfAtual' => $usuario->exists ? Cpf::formatar($usuario->cpf) : '',
        ];
    }
}
