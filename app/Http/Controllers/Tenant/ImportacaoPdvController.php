<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\ImportacaoPdvs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Importação de PDVs por planilha: envio → revisão → gravação dos selecionados.
 */
class ImportacaoPdvController extends Controller
{
    public function __construct(private ImportacaoPdvs $importacao) {}

    public function create(): View
    {
        return view('tenant.pdvs.importar');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'arquivo' => ['required', 'file', 'extensions:csv,txt,xlsx', 'max:'.config('trade.importacao_pdvs.max_kb')],
        ], [
            'arquivo.extensions' => 'Envie uma planilha CSV ou XLSX.',
        ]);

        $arquivo = $request->file('arquivo');
        $linhas = $this->importacao->analisar(
            $this->importacao->ler($arquivo->getRealPath(), $arquivo->getClientOriginalExtension())
        );

        $id = $this->importacao->salvarRevisao($request->user(), $arquivo->getClientOriginalName(), $linhas);

        return redirect()->route('pdvs.importar.revisar', $id);
    }

    public function show(Request $request, string $importacao): View
    {
        $revisao = $this->importacao->carregarRevisao($importacao, $request->user());
        abort_unless($revisao, 404);

        $linhas = collect($revisao['linhas']);

        return view('tenant.pdvs.revisar', [
            'id' => $importacao,
            'arquivo' => $revisao['arquivo'],
            'linhas' => $linhas,
            'resumo' => $linhas->countBy('acao')->all() + ['novo' => 0, 'atualizar' => 0, 'erro' => 0],
        ]);
    }

    public function update(Request $request, string $importacao): RedirectResponse
    {
        $revisao = $this->importacao->carregarRevisao($importacao, $request->user());
        abort_unless($revisao, 404);

        $request->validate([
            'linhas' => ['required', 'array', 'min:1'],
            'linhas.*' => ['integer'],
        ], [
            'linhas.required' => 'Selecione ao menos uma linha para gravar.',
        ]);

        $escolhidas = array_map('intval', $request->input('linhas'));
        $selecionadas = array_values(array_filter(
            $revisao['linhas'],
            fn ($l) => in_array((int) $l['linha'], $escolhidas, true)
        ));

        $resultado = $this->importacao->gravar($selecionadas);
        $this->importacao->descartarRevisao($importacao);

        $mensagem = "Importação concluída: {$resultado['criados']} PDV(s) criado(s), {$resultado['atualizados']} atualizado(s).";
        if ($resultado['ignorados']) {
            $mensagem .= " {$resultado['ignorados']} linha(s) com erro ignorada(s).";
        }

        return redirect()->route('pdvs.index')->with('status', $mensagem);
    }

    public function destroy(Request $request, string $importacao): RedirectResponse
    {
        abort_unless($this->importacao->carregarRevisao($importacao, $request->user()), 404);
        $this->importacao->descartarRevisao($importacao);

        return redirect()->route('pdvs.importar')->with('status', 'Importação descartada. Nada foi gravado.');
    }
}
