<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\GerarExportacao;
use App\Models\Exportacao;
use App\Services\Coletas\ResultadosColeta;
use App\Services\Operacao\Indicadores;
use App\Support\Contexto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fila de downloads: exportações grandes geradas em segundo plano, que o usuário baixa quando ficam
 * prontas (link privado, só do dono, por 7 dias).
 */
class ExportacaoController extends Controller
{
    public function index(Request $request): View
    {
        return view('tenant.exportacoes.index', [
            'exportacoes' => Exportacao::doUsuario($request->user())->latest('id')->limit(50)->get(),
        ]);
    }

    public function store(Request $request, Indicadores $indicadores, ResultadosColeta $coletas): RedirectResponse
    {
        $eu = $request->user();
        $dados = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(Exportacao::TIPOS))],
            'relatorio' => ['nullable', Rule::in(array_keys(ResultadosColeta::TIPOS))],
        ]);

        if ($dados['tipo'] === 'indicadores') {
            $filtros = $indicadores->filtros($request, $indicadores->opcoes($eu));
            $extra = [];
        } else {
            $d = $request->validate([
                'inicio' => ['nullable', 'date_format:Y-m-d'], 'fim' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:inicio'],
                'rede_id' => ['nullable', 'integer'], 'cidade' => ['nullable', 'string', 'max:80'], 'dias' => ['nullable', 'integer', 'between:1,365'],
            ]);
            $filtros = [
                'inicio' => $d['inicio'] ?? today()->subDays(29)->toDateString(), 'fim' => $d['fim'] ?? today()->toDateString(),
                'rede_id' => $d['rede_id'] ?? null, 'cidade' => $d['cidade'] ?? null, 'dias' => (int) ($d['dias'] ?? 60),
            ];
            $extra = ['relatorio' => $dados['relatorio'] ?? 'preco'];
        }

        $e = Exportacao::create([
            'uuid' => (string) Str::uuid(),
            'agencia_id' => Contexto::agenciaLocal()?->id ?? $eu->agencia_id,
            'usuario_id' => $eu->id,
            'tipo' => $dados['tipo'],
            'parametros' => ['filtros' => $filtros, 'usuario' => ['id' => $eu->id, 'nome' => $eu->nome, 'perfil' => $eu->perfil->value, 'agencia_id' => $eu->agencia_id, 'industria_id' => $eu->industria_id]] + $extra,
        ]);
        GerarExportacao::dispatch($e->id);

        return redirect()->route('exportacoes.index')->with('status', 'Exportação pedida. Ela aparece aqui quando estiver pronta.');
    }

    private function doDono(Request $request, int $id): Exportacao
    {
        return Exportacao::doUsuario($request->user())->findOrFail($id);
    }

    public function baixar(Request $request, int $exportacao): StreamedResponse
    {
        $e = $this->doDono($request, $exportacao);
        abort_unless($e->disponivel() && Storage::disk('local')->exists((string) $e->arquivo), 404, 'Este arquivo não está mais disponível. Peça a exportação de novo.');

        return Storage::disk('local')->download($e->arquivo, $e->nome, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Request $request, int $exportacao): RedirectResponse
    {
        $e = $this->doDono($request, $exportacao);
        if ($e->arquivo) {
            Storage::disk('local')->delete($e->arquivo);
        }
        $e->delete();

        return redirect()->route('exportacoes.index')->with('status', 'Exportação removida.');
    }
}
