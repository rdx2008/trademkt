<?php

namespace App\Services\Coletas;

use App\Models\ColetaResposta;
use App\Models\ModeloColeta;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Relatórios das coletas (fase 10): preço, validade, estoque e share. Cada relatório é uma tabela
 * (colunas e linhas) montada a partir dos campos com "papel" definido nos modelos, então funciona com
 * qualquer modelo que tenha esses papéis, sem depender do nome das perguntas.
 */
class ResultadosColeta
{
    public const TIPOS = ['preco' => 'Preço', 'validade' => 'Validade', 'estoque' => 'Estoque', 'share' => 'Share de gôndola'];

    /**
     * @param  array{inicio: string, fim: string, rede_id?: ?int, cidade?: ?string, dias?: ?int}  $f
     * @return Collection<int, ColetaResposta>
     */
    public function respostas(User $usuario, array $f): Collection
    {
        return ColetaResposta::query()
            ->whereHas('visita', function ($q) use ($usuario, $f) {
                $q->visiveisPara($usuario)->whereDate('data', '>=', $f['inicio'])->whereDate('data', '<=', $f['fim']);
                if (! empty($f['rede_id'])) {
                    $q->whereHas('pdv', fn ($p) => $p->where('rede_id', $f['rede_id']));
                }
                if (! empty($f['cidade'])) {
                    $q->whereHas('pdv', fn ($p) => $p->where('cidade', $f['cidade']));
                }
            })
            ->with(['visita.pdv.rede', 'sku', 'modelo'])
            ->orderBy('respondida_em')
            ->get();
    }

    /** @return array{colunas: list<string>, linhas: list<list<mixed>>, resumo: list<array{string, mixed}>} */
    public function relatorio(string $tipo, User $usuario, array $f): array
    {
        $respostas = $this->respostas($usuario, $f);

        return match ($tipo) {
            'validade' => $this->validade($respostas, (int) ($f['dias'] ?? 60)),
            'estoque' => $this->estoque($respostas),
            'share' => $this->share($respostas),
            default => $this->preco($respostas),
        };
    }

    /** Valor da resposta pelo papel do campo, na versão em que foi dada. */
    private function valor(ColetaResposta $r, string $papel): mixed
    {
        $chave = $r->modelo->chaveDoPapel($papel, $r->versao);

        return $chave === null ? null : ($r->respostas[$chave] ?? null);
    }

    private function loja(ColetaResposta $r): string
    {
        return $r->visita->pdv->nome;
    }

    private function rede(ColetaResposta $r): string
    {
        return $r->visita->pdv->rede?->nome ?? 'Sem rede';
    }

    // ------------------------------------------------------------------ preço

    private function preco(Collection $respostas): array
    {
        $proprio = $respostas->filter(fn ($r) => $r->sku_id && $this->valor($r, 'preco_proprio') !== null);
        $linhas = $proprio->groupBy(fn ($r) => $r->sku_id.'|'.$this->rede($r))->map(function ($grupo) {
            $precos = $grupo->map(fn ($r) => (float) $this->valor($r, 'preco_proprio'));
            $r0 = $grupo->first();

            return [$r0->sku->codigo.' · '.$r0->sku->descricao, $this->rede($r0), $grupo->pluck('visita_id')->unique()->count(), round($precos->min(), 2), round($precos->avg(), 2), round($precos->max(), 2)];
        })->sortBy([[0, 'asc'], [1, 'asc']])->values()->all();

        $concorrente = $respostas->filter(fn ($r) => $this->valor($r, 'preco_concorrente') !== null)->groupBy(fn ($r) => $this->rede($r));
        $comparativo = [];
        foreach ($proprio->groupBy(fn ($r) => $this->rede($r)) as $rede => $grupo) {
            $nosso = round($grupo->avg(fn ($r) => (float) $this->valor($r, 'preco_proprio')), 2);
            $deles = isset($concorrente[$rede]) ? round($concorrente[$rede]->avg(fn ($r) => (float) $this->valor($r, 'preco_concorrente')), 2) : null;
            $comparativo[] = [$rede, $nosso, $deles, $deles === null ? null : round($nosso - $deles, 2)];
        }

        return [
            'colunas' => ['Produto', 'Rede', 'Lojas', 'Menor preço', 'Preço médio', 'Maior preço'],
            'linhas' => $linhas,
            'resumo' => array_merge([['Produtos pesquisados', $proprio->pluck('sku_id')->unique()->count()]], array_map(
                fn ($c) => ["{$c[0]}: nosso médio R$ {$c[1]}".($c[2] !== null ? " · concorrente R$ {$c[2]} · diferença R$ {$c[3]}" : ''), null],
                $comparativo,
            )),
        ];
    }

    // --------------------------------------------------------------- validade

    private function validade(Collection $respostas, int $dias): array
    {
        $limite = today()->addDays($dias);
        $linhas = [];
        foreach ($respostas as $r) {
            $validade = $this->valor($r, 'validade');
            if (! $validade || ! $r->sku_id) {
                continue;
            }
            $data = Carbon::parse($validade)->startOfDay();
            if ($data->gt($limite)) {
                continue;
            }
            $linhas[] = [$data->toDateString(), (int) today()->diffInDays($data, false), $r->sku->codigo.' · '.$r->sku->descricao, $this->loja($r), $this->rede($r),
                $this->valor($r, 'quantidade_vencimento'), $this->valor($r, 'lote')];
        }
        usort($linhas, fn ($a, $b) => $a[0] <=> $b[0]);
        $vencidos = count(array_filter($linhas, fn ($l) => $l[1] < 0));

        return [
            'colunas' => ['Validade', 'Dias', 'Produto', 'Loja', 'Rede', 'Quantidade', 'Lote'],
            'linhas' => $linhas,
            'resumo' => [['Produtos a vencer em até '.$dias.' dias', count($linhas)], ['Já vencidos', $vencidos], ['Vencem em até 7 dias', count(array_filter($linhas, fn ($l) => $l[1] >= 0 && $l[1] <= 7))]],
        ];
    }

    // ---------------------------------------------------------------- estoque

    private function estoque(Collection $respostas): array
    {
        // O último levantamento de cada produto em cada loja.
        $ultimos = $respostas->filter(fn ($r) => $r->sku_id && $this->valor($r, 'estoque_gondola') !== null)
            ->groupBy(fn ($r) => $r->visita->pdv_id.'|'.$r->sku_id)->map(fn ($g) => $g->sortByDesc(fn ($r) => [$r->respondida_em->timestamp, $r->id])->first());
        $porSku = $ultimos->groupBy('sku_id')->map(function ($grupo) {
            $s = $grupo->first()->sku;
            $gondola = $grupo->sum(fn ($r) => (int) $this->valor($r, 'estoque_gondola'));

            return [$s->codigo.' · '.$s->descricao, $grupo->count(), $gondola,
                $grupo->sum(fn ($r) => (int) $this->valor($r, 'estoque_loja')), $grupo->sum(fn ($r) => (int) $this->valor($r, 'estoque_deposito')),
                $grupo->filter(fn ($r) => (int) $this->valor($r, 'estoque_gondola') === 0)->count()];
        })->sortByDesc(fn ($l) => $l[5])->values()->all();

        return [
            'colunas' => ['Produto', 'Lojas levantadas', 'Na gôndola', 'Na loja', 'No depósito', 'Lojas com gôndola vazia'],
            'linhas' => $porSku,
            'resumo' => [['Produtos levantados', count($porSku)], ['Lojas levantadas', $ultimos->pluck('visita.pdv_id')->unique()->count()],
                ['Gôndolas vazias (produto × loja)', $ultimos->filter(fn ($r) => (int) $this->valor($r, 'estoque_gondola') === 0)->count()]],
        ];
    }

    // ------------------------------------------------------------------ share

    private function share(Collection $respostas): array
    {
        $linhas = [];
        foreach ($respostas as $r) {
            $marca = $this->valor($r, 'frentes_marca');
            $conc = $this->valor($r, 'frentes_concorrentes');
            if ($marca === null || $conc === null || ((int) $marca + (int) $conc) === 0) {
                continue;
            }
            $linhas[] = ['pdv' => $r->visita->pdv, 'rede' => $this->rede($r), 'data' => $r->visita->data->toDateString(), 'marca' => (int) $marca, 'conc' => (int) $conc];
        }
        $porRede = collect($linhas)->groupBy('rede')->map(fn ($g, $rede) => [$rede, $g->count(), $g->sum('marca'), $g->sum('conc'), round(100 * $g->sum('marca') / max(1, $g->sum('marca') + $g->sum('conc')), 1)])->sortByDesc(fn ($l) => $l[4])->values()->all();
        $total = collect($linhas)->sum('marca') + collect($linhas)->sum('conc');
        $geral = $total > 0 ? round(100 * collect($linhas)->sum('marca') / $total, 1) : null;

        return [
            'colunas' => ['Rede', 'Pesquisas', 'Frentes da marca', 'Frentes de concorrentes', 'Share (%)'],
            'linhas' => $porRede,
            'resumo' => [['Share geral (%)', $geral], ['Pesquisas', count($linhas)]],
        ];
    }

    /** @return list<string> tipos de modelo ativos que têm dados no período (para as abas) */
    public function modelosAtivos(User $usuario): array
    {
        return ModeloColeta::query()->where('ativo', true)->pluck('tipo')->unique()->values()->all();
    }
}
