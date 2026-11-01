<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Catalogo\ImportacaoSkus;
use App\Services\Catalogo\LeitorPdfCatalogo;
use App\Services\Catalogo\LeitorPlanilhaSkus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Importação do catálogo de SKUs por planilha (CSV/XLSX) ou PDF: envio → revisão → gravação
 * dos selecionados. Nada vai para `skus` antes da confirmação.
 */
class ImportacaoSkuController extends Controller
{
    public function __construct(
        private ImportacaoSkus $importacao,
        private LeitorPlanilhaSkus $planilha,
        private LeitorPdfCatalogo $pdf,
    ) {}

    public function create(Request $request): View
    {
        return view('tenant.skus.importar', [
            'industrias' => $this->importacao->industriasDoUsuario($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $industrias = $this->importacao->industriasDoUsuario($request->user());

        // O gerente de trade só importa na própria marca.
        if ($industrias->count() === 1) {
            $request->merge(['industria_id' => $industrias->keys()->first()]);
        }

        $request->validate([
            'industria_id' => ['required', Rule::in($industrias->keys()->all())],
            'arquivo' => ['required', 'file', 'extensions:csv,txt,xlsx,pdf', 'max:'.config('trade.importacao_skus.max_kb')],
        ], [
            'industria_id.required' => 'Escolha a indústria dos produtos.',
            'industria_id.in' => 'Indústria inválida.',
            'arquivo.extensions' => 'Envie uma planilha CSV ou XLSX, ou um catálogo em PDF.',
        ]);

        $arquivo = $request->file('arquivo');
        $extensao = strtolower($arquivo->getClientOriginalExtension());
        $industriaId = $request->integer('industria_id');

        if ($extensao === 'pdf') {
            if (! str_starts_with((string) file_get_contents($arquivo->getRealPath(), false, null, 0, 5), '%PDF-')) {
                throw ValidationException::withMessages(['arquivo' => 'O arquivo não é um PDF válido.']);
            }
            // PDFs grandes demoram mais que o padrão do PHP-FPM; na linha de comando não há limite.
            if ((int) ini_get('max_execution_time') > 0) {
                set_time_limit(180);
            }
            @ini_set('memory_limit', '768M');
            $itens = $this->pdf->ler($arquivo->getRealPath());
            $fonte = 'pdf';
        } else {
            $itens = $this->planilha->ler($arquivo->getRealPath(), $extensao);
            $fonte = 'planilha';
        }

        $analisados = $this->importacao->analisar($industriaId, $itens);
        $id = $this->importacao->salvarRevisao($request->user(), $industriaId, $fonte, $arquivo->getClientOriginalName(), $analisados);

        return redirect()->route('skus.importar.revisar', $id);
    }

    public function show(Request $request, string $importacao): View
    {
        $revisao = $this->revisao($request, $importacao);
        $itens = collect($revisao['itens']);

        return view('tenant.skus.revisar', [
            'id' => $importacao,
            'arquivo' => $revisao['arquivo'],
            'fonte' => $revisao['fonte'],
            'industria' => $this->importacao->industriasDoUsuario($request->user())->get($revisao['industria_id']),
            'itens' => $itens,
            'resumo' => $itens->countBy('acao')->all() + ['novo' => 0, 'atualizar' => 0, 'erro' => 0],
            'comAviso' => $itens->filter(fn ($i) => $i['acao'] !== 'erro' && $i['avisos'] !== [])->count(),
        ]);
    }

    public function update(Request $request, string $importacao): RedirectResponse
    {
        $revisao = $this->revisao($request, $importacao);

        $request->validate([
            'itens' => ['required', 'array', 'min:1'],
            'itens.*' => ['integer'],
        ], [
            'itens.required' => 'Selecione ao menos um produto para gravar.',
        ]);

        $escolhidos = array_map('intval', $request->input('itens'));
        $selecionados = array_values(array_filter(
            $revisao['itens'],
            fn ($i) => in_array((int) $i['linha'], $escolhidos, true)
        ));

        $resultado = $this->importacao->gravar($revisao['industria_id'], $selecionados);
        $this->importacao->descartarRevisao($importacao);

        $mensagem = "Importação concluída: {$resultado['criados']} SKU(s) criado(s), {$resultado['atualizados']} atualizado(s).";
        if ($resultado['ignorados']) {
            $mensagem .= " {$resultado['ignorados']} item(ns) com erro ignorado(s).";
        }
        if ($resultado['com_alerta_de_gtin']) {
            $mensagem .= " {$resultado['com_alerta_de_gtin']} SKU(s) com GTIN a revisar (filtro «Só com GTIN a revisar»).";
        }

        return redirect()->route('skus.index')->with('status', $mensagem);
    }

    public function destroy(Request $request, string $importacao): RedirectResponse
    {
        $this->revisao($request, $importacao);
        $this->importacao->descartarRevisao($importacao);

        return redirect()->route('skus.importar')->with('status', 'Importação descartada. Nada foi gravado.');
    }

    /** Planilha de exemplo com as colunas aceitas. */
    public function modelo(): BinaryFileResponse
    {
        $caminho = tempnam(sys_get_temp_dir(), 'modelo-skus').'.xlsx';

        $escritor = new Writer;
        $escritor->openToFile($caminho);
        $escritor->addRow(Row::fromValues([
            'Código', 'Descrição', 'Categoria',
            'GTIN unidade', 'GTIN reembalagem', 'Qtd reembalagem', 'GTIN caixa master', 'Qtd caixa master',
        ]));
        $escritor->addRow(Row::fromValues([
            '10FL01005', 'Fósforos de segurança longos', 'Fósforos',
            '7896279200011', '17896279200018', '20', '27896279200015', '120',
        ]));
        $escritor->addRow(Row::fromValues([
            '20LD01600', 'Prendedor Gaboardi', 'Prendedores',
            '7896384500129', '', '', '17896384500126', '50',
        ]));
        $escritor->close();

        return response()->download($caminho, 'modelo-produtos.xlsx')->deleteFileAfterSend();
    }

    /** @return array<string, mixed> */
    private function revisao(Request $request, string $id): array
    {
        $revisao = $this->importacao->carregarRevisao($id, $request->user());
        abort_unless($revisao, 404);
        abort_unless($this->importacao->industriasDoUsuario($request->user())->has($revisao['industria_id']), 403);

        return $revisao;
    }
}
