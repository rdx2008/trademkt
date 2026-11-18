<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Http\Controllers\Controller;
use App\Models\Afastamento;
use App\Models\Ocorrencia;
use App\Models\User;
use App\Services\Promotores\Cadastro;
use App\Services\Promotores\FichaOperacional;
use App\Services\Promotores\Redistribuicao;
use App\Services\Rede\Operacao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ficha do promotor: agenda e histórico nas indústrias atendidas, afastamentos, ocorrências e
 * aparelhos conectados. Admin e supervisor consultam; só o admin registra e altera.
 */
class FichaPromotorController extends Controller
{
    public function __construct(private Cadastro $cadastro, private FichaOperacional $ficha, private Redistribuicao $redistribuicao) {}

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

    public function show(Request $request, int $promotor): View
    {
        $eu = $this->consultar($request);
        $usuario = $this->promotor($promotor);

        return view('tenant.promotores.ficha', [
            'p' => $usuario,
            'podeGerir' => $eu->podeGerirPromotores(),
            'operacional' => $this->ficha->de(tenant(), $usuario->id),
            'afastamentos' => $usuario->afastamentos()->limit(20)->get(),
            'afastadoHoje' => $usuario->afastamentoEm(),
            'ocorrencias' => $usuario->ocorrencias()->limit(30)->get(),
            'aparelhos' => $usuario->tokens()->orderByDesc('last_used_at')->orderByDesc('id')->get(),
            'industrias' => app(Operacao::class)->industriasDe(tenant()),
            'habilitadas' => $usuario->industriasHabilitadas(),
            'mes' => $mes = $this->mes($request),
            'skusPorDia' => $this->resumoSkus($usuario, $mes),
            'tiposAfastamento' => Afastamento::TIPOS,
            'tiposOcorrencia' => Ocorrencia::TIPOS,
        ]);
    }

    /** Indústrias em que o promotor pode receber visitas. Nenhuma marcada = todas as atendidas. */
    public function habilitar(Request $request, int $promotor): RedirectResponse
    {
        $eu = $this->gerir($request);
        $usuario = $this->promotor($promotor);
        $atendidas = app(Operacao::class)->industriasDe(tenant())->pluck('id')->all();
        $dados = $request->validate([
            'todas' => ['nullable', 'boolean'],
            'industrias' => ['nullable', 'array'],
            'industrias.*' => ['string', Rule::in($atendidas)],
        ]);
        $todas = $request->boolean('todas');
        $escolhidas = $todas ? [] : array_values(array_unique($dados['industrias'] ?? []));

        DB::transaction(function () use ($usuario, $escolhidas, $todas) {
            $usuario->forceFill(['habilitacao_restrita' => ! $todas])->save();
            DB::table('promotor_industrias')->where('usuario_id', $usuario->id)->delete();
            foreach ($escolhidas as $industria) {
                DB::table('promotor_industrias')->insert(['usuario_id' => $usuario->id, 'industria_id' => $industria, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->cadastro->auditar($usuario, 'habilitacao_alterada', $eu, ['todas' => $todas, 'industrias' => $escolhidas]);

        return redirect()->route('promotores.ficha', $usuario)->with('status', match (true) {
            $todas => 'Promotor habilitado para todas as indústrias atendidas.',
            $escolhidas === [] => 'Nenhuma indústria habilitada: o promotor não recebe visitas até você marcar alguma.',
            default => 'Habilitação atualizada.',
        });
    }

    private function periodo(Request $request): array
    {
        $dados = $request->validate([
            'inicio' => ['nullable', 'date_format:Y-m-d'],
            'fim' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:inicio'],
        ]);

        return [$dados['inicio'] ?? today()->toDateString(), $dados['fim'] ?? today()->addDays(14)->toDateString()];
    }

    /** Prévia: para onde iriam as visitas já atribuídas ao promotor no período. */
    public function redistribuir(Request $request, int $promotor): View
    {
        $eu = $this->consultar($request);
        $usuario = $this->promotor($promotor);
        [$inicio, $fim] = $this->periodo($request);

        return view('tenant.promotores.redistribuir', [
            'p' => $usuario,
            'inicio' => $inicio,
            'fim' => $fim,
            'linhas' => collect($this->redistribuicao->executar(tenant(), $usuario, $inicio, $fim, $eu)),
        ]);
    }

    public function aplicarRedistribuicao(Request $request, int $promotor): RedirectResponse
    {
        $eu = $this->consultar($request);
        $usuario = $this->promotor($promotor);
        [$inicio, $fim] = $this->periodo($request);
        $linhas = collect($this->redistribuicao->executar(tenant(), $usuario, $inicio, $fim, $eu, true));
        $movidas = $linhas->where('movida', true)->count();
        $this->cadastro->auditar($usuario, 'visitas_redistribuidas', $eu, ['movidas' => $movidas, 'restantes' => $linhas->count() - $movidas, 'inicio' => $inicio, 'fim' => $fim]);

        $restantes = $linhas->count() - $movidas;

        return redirect()->route('promotores.ficha', $usuario)->with('status', $movidas.' visita(s) passada(s) para colegas.'.($restantes > 0 ? ' '.$restantes.' ficaram com ele por falta de substituto: atribua em Roteiros.' : ''));
    }

    private function mes(Request $request): string
    {
        $mes = (string) $request->query('mes', now()->format('Y-m'));

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) ? $mes : now()->format('Y-m');
    }

    /** @return Collection<string, array{visitas: int, skus: int, itens: int}> por dia */
    private function resumoSkus(User $usuario, string $mes)
    {
        $inicio = $mes.'-01';
        $fim = Carbon::parse($inicio)->endOfMonth()->toDateString();

        return $this->ficha->skusTrabalhados(tenant(), $usuario->id, $inicio, $fim)
            ->groupBy('data')
            ->map(fn ($dia) => [
                'visitas' => $dia->pluck('visita_id')->unique()->count(),
                'skus' => $dia->map(fn ($l) => $l->industria.'|'.$l->codigo)->unique()->count(),
                'itens' => $dia->count(),
            ]);
    }

    /** Relatório do mês em CSV: uma linha por SKU trabalhado. Textos protegidos contra fórmulas. */
    public function skusCsv(Request $request, int $promotor): StreamedResponse
    {
        $this->consultar($request);
        $usuario = $this->promotor($promotor);
        $mes = $this->mes($request);
        $inicio = $mes.'-01';
        $fim = Carbon::parse($inicio)->endOfMonth()->toDateString();
        $linhas = $this->ficha->skusTrabalhados(tenant(), $usuario->id, $inicio, $fim);
        $texto = fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'".$v : (string) $v;

        return response()->streamDownload(function () use ($linhas, $texto) {
            $saida = fopen('php://output', 'w');
            fwrite($saida, "\xEF\xBB\xBF");
            fputcsv($saida, ['Dia', 'Indústria', 'PDV', 'Código', 'Produto', 'Estoque'], ';');
            foreach ($linhas as $l) {
                fputcsv($saida, [Carbon::parse($l->data)->format('d/m/Y'), $texto($l->industria), $texto($l->pdv), $texto($l->codigo), $texto($l->descricao), $l->situacao === 'tem_estoque' ? 'Tem estoque' : 'Somente na gôndola'], ';');
            }
            fclose($saida);
        }, 'skus-trabalhados-'.$mes.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function afastar(Request $request, int $promotor): RedirectResponse
    {
        $eu = $this->gerir($request);
        $usuario = $this->promotor($promotor);
        $dados = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(Afastamento::TIPOS))],
            'inicio' => ['required', 'date'],
            'fim' => ['required', 'date', 'after_or_equal:inicio'],
            'observacao' => ['nullable', 'string', 'max:300'],
        ]);
        $usuario->afastamentos()->create($dados + ['criado_por' => $eu->id]);
        $this->cadastro->auditar($usuario, 'afastamento_registrado', $eu, ['tipo' => $dados['tipo'], 'inicio' => $dados['inicio'], 'fim' => $dados['fim']]);

        $resposta = redirect()->route('promotores.ficha', $usuario)->with('status', 'Afastamento registrado.');
        $pendentes = count($this->redistribuicao->executar(tenant(), $usuario, $dados['inicio'], $dados['fim'], $eu));

        return $pendentes > 0
            ? $resposta->with('redistribuir', ['n' => $pendentes, 'url' => route('promotores.redistribuir', ['promotor' => $usuario->id, 'inicio' => $dados['inicio'], 'fim' => $dados['fim']])])
            : $resposta;
    }

    public function encerrarAfastamento(Request $request, int $promotor, int $afastamento): RedirectResponse
    {
        $eu = $this->gerir($request);
        $usuario = $this->promotor($promotor);
        $registro = $usuario->afastamentos()->findOrFail($afastamento);
        $registro->delete();
        $this->cadastro->auditar($usuario, 'afastamento_removido', $eu, ['tipo' => $registro->tipo]);

        return redirect()->route('promotores.ficha', $usuario)->with('status', 'Afastamento removido.');
    }

    public function ocorrencia(Request $request, int $promotor): RedirectResponse
    {
        $eu = $this->gerir($request);
        $usuario = $this->promotor($promotor);
        $dados = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(Ocorrencia::TIPOS))],
            'data' => ['required', 'date', 'before_or_equal:today'],
            'descricao' => ['required', 'string', 'max:2000'],
        ]);
        $usuario->ocorrencias()->create($dados + ['registrado_por' => $eu->id]);
        $this->cadastro->auditar($usuario, 'ocorrencia_registrada', $eu, ['tipo' => $dados['tipo']]);

        return redirect()->route('promotores.ficha', $usuario)->with('status', 'Ocorrência registrada.');
    }

    public function editarOcorrencia(Request $request, int $promotor, int $ocorrencia): RedirectResponse
    {
        $eu = $this->gerir($request);
        $usuario = $this->promotor($promotor);
        $registro = $usuario->ocorrencias()->findOrFail($ocorrencia);
        $dados = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(Ocorrencia::TIPOS))],
            'data' => ['required', 'date', 'before_or_equal:today'],
            'descricao' => ['required', 'string', 'max:2000'],
        ]);
        $registro->update($dados);
        $this->cadastro->auditar($usuario, 'ocorrencia_editada', $eu, ['tipo' => $dados['tipo']]);

        return redirect()->route('promotores.ficha', $usuario)->with('status', 'Ocorrência atualizada.');
    }

    public function removerOcorrencia(Request $request, int $promotor, int $ocorrencia): RedirectResponse
    {
        $eu = $this->gerir($request);
        $usuario = $this->promotor($promotor);
        $registro = $usuario->ocorrencias()->findOrFail($ocorrencia);
        $registro->delete();
        $this->cadastro->auditar($usuario, 'ocorrencia_removida', $eu, ['tipo' => $registro->tipo]);

        return redirect()->route('promotores.ficha', $usuario)->with('status', 'Ocorrência removida.');
    }

    public function revogarAparelho(Request $request, int $promotor, int $token): RedirectResponse
    {
        $eu = $this->gerir($request);
        $usuario = $this->promotor($promotor);
        $aparelho = $usuario->tokens()->findOrFail($token);
        $aparelho->delete();
        $this->cadastro->auditar($usuario, 'aparelho_desconectado', $eu, ['aparelho' => $aparelho->name]);

        return redirect()->route('promotores.ficha', $usuario)->with('status', 'Aparelho desconectado. O promotor precisa entrar de novo no app.');
    }
}
