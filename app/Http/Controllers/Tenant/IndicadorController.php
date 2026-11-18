<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\FotoAvaliacao;
use App\Services\Operacao\ExcelIndicadores;
use App\Services\Operacao\Indicadores;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IndicadorController extends Controller
{
    private function contexto(Request $r, Indicadores $s): array
    {
        $opcoes = $s->opcoes($r->user());

        return [$opcoes, $s->filtros($r, $opcoes)];
    }

    public function index(Request $r, Indicadores $s)
    {
        [$opcoes, $filtros] = $this->contexto($r, $s);

        return view('tenant.indicadores.index', ['opcoes' => $opcoes, 'filtros' => $filtros, 'resumo' => $s->resumo($r->user(), $filtros), 'producao' => $s->producao($r->user(), $filtros)->take(100), 'rupturas' => $s->rupturas($r->user(), $filtros)->limit(100)->get(), 'tempos' => $s->tempos($r->user(), $filtros)->take(100)]);
    }

    public function galeria(Request $r, Indicadores $s)
    {
        [$opcoes, $filtros] = $this->contexto($r, $s);
        $av = $r->validate(['nota_min' => ['nullable', 'integer', 'between:1,5'], 'nota_max' => ['nullable', 'integer', 'between:1,5'], 'tag' => ['nullable', Rule::in(array_keys(FotoAvaliacao::TAGS))]]);
        $comAvaliacao = fn ($q) => $q->whereHas('avaliacoes', fn ($a) => $a
            ->when($av['nota_min'] ?? null, fn ($x, $n) => $x->where('nota', '>=', $n))
            ->when($av['nota_max'] ?? null, fn ($x, $n) => $x->where('nota', '<=', $n))
            ->when($av['tag'] ?? null, fn ($x, $t) => $x->whereJsonContains('tags', $t)));
        $filtrandoAvaliacao = ($av['nota_min'] ?? null) || ($av['nota_max'] ?? null) || ($av['tag'] ?? null);
        $visitas = $s->visitas($r->user(), $filtros)->whereHas('fotos', fn ($q) => $filtrandoAvaliacao ? $comAvaliacao($q) : $q)->whereDate('data', '>=', $filtros['inicio'])->whereDate('data', '<=', $filtros['fim'])->with(['fotos.avaliacoes', 'pdv', 'industria', 'roteiro'])->orderByDesc('data')->paginate(8)->withQueryString();
        $avaliacao = $av;

        return view('tenant.indicadores.galeria', compact('opcoes', 'filtros', 'visitas', 'avaliacao'));
    }

    public function exportar(Request $r, Indicadores $s)
    {
        [, $f] = $this->contexto($r, $s);
        $arquivo = tempnam(sys_get_temp_dir(), 'trade-relatorio-');
        app(ExcelIndicadores::class)->gravar($r->user(), $f, $arquivo);

        return response()->download($arquivo, 'indicadores-'.$f['inicio'].'-'.$f['fim'].'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }
}
