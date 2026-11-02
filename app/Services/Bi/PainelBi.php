<?php

namespace App\Services\Bi;

use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Models\User;
use App\Models\Visita;
use App\Models\VisitaSku;
use App\Services\Operacao\Indicadores;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * BI da indústria e da agência: indicadores, tendências e rankings do período filtrado.
 *
 * Usa as consultas e o escopo de permissões de {@see Indicadores}: cada perfil só enxerga
 * as visitas que já podia ver nas demais telas. A visão muda só o foco do que é mostrado:
 * - indústria: cobertura da marca, desempenho das agências prestadoras, SKUs e rupturas;
 * - agência: produtividade dos promotores, atendimento por indústria cliente e roteiros.
 */
class PainelBi
{
    private const DIAS_SEMANA = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

    public function __construct(private Indicadores $indicadores) {}

    /** 'industria' ou 'agencia': o gerente de trade sempre enxerga a visão da marca. */
    public static function visao(User $usuario): string
    {
        return $usuario->perfil === Perfil::GerenteTrade || tenant()->tipo === TipoInstalacao::Industria
            ? 'industria'
            : 'agencia';
    }

    /**
     * @param  array<string, mixed>  $f  filtros validados por Indicadores::filtros
     * @return array<string, mixed>
     */
    public function gerar(User $usuario, array $f): array
    {
        $visao = self::visao($usuario);
        $atual = $this->indicadores->resumo($usuario, $f);
        $anterior = $this->indicadores->resumo($usuario, $this->periodoAnterior($f));

        $agenda = fn () => $this->indicadores->visitas($usuario, $f)
            ->whereDate('data', '>=', $f['inicio'])->whereDate('data', '<=', $f['fim'])
            ->select('visitas.id');
        $execucoes = fn () => $this->indicadores->execucoes($usuario, $f)->select('visitas.id');

        $dados = [
            'visao' => $visao,
            'titulo' => $visao === 'industria' ? 'BI da indústria' : 'BI da agência',
            'filtros' => $f,
            'kpis' => $this->kpis($atual, $anterior),
            'demandas' => [
                'abertas' => $atual['demandas'],
                'no_prazo' => $atual['demandas_no_prazo'],
                'atrasadas' => $atual['demandas_atrasadas'],
                'relatos_ruptura' => $atual['relatos_ruptura'],
                'percentual_no_prazo' => $atual['demandas'] ? round($atual['demandas_no_prazo'] / $atual['demandas'] * 100, 1) : null,
            ],
            'tendencia' => $this->tendencia($agenda(), $f),
            'estoque' => $this->estoque($execucoes()),
            'topSkus' => $this->topSkus($execucoes(), false),
            'topRupturasSku' => $this->topSkus($execucoes(), true),
            'rupturasPorPdv' => $this->rupturasPorPdv($execucoes()),
            'porRede' => $this->desempenho($agenda(), 'redes', 'redes.id', 'redes.nome', fn ($q) => $q
                ->join('pdvs', 'pdvs.id', '=', 'visitas.pdv_id')->join('redes', 'redes.id', '=', 'pdvs.rede_id')),
            'porRegiao' => $this->desempenho($agenda(), 'regioes', 'regioes.id', 'regioes.nome', fn ($q) => $q
                ->join('pdvs', 'pdvs.id', '=', 'visitas.pdv_id')->join('regioes', 'regioes.id', '=', 'pdvs.regiao_id')),
            'pdvsCriticos' => $this->pdvsCriticos($agenda()),
        ];

        if ($visao === 'industria') {
            $dados['porAgencia'] = $this->desempenho($agenda(), 'agencias', 'agencias.id', 'agencias.nome', fn ($q) => $q
                ->join('agencias', 'agencias.id', '=', 'visitas.agencia_id'));
        } else {
            $dados['porIndustria'] = $this->desempenho($agenda(), 'industrias', 'industrias.id', 'industrias.nome', fn ($q) => $q
                ->join('industrias', 'industrias.id', '=', 'visitas.industria_id'));
            $dados['porPromotor'] = $this->porPromotor($agenda(), $execucoes());
            $dados['porDiaSemana'] = $this->porDiaSemana($execucoes());
        }

        return $dados;
    }

    /** Período imediatamente anterior, com a mesma duração, para comparar as tendências. */
    public function periodoAnterior(array $f): array
    {
        $inicio = Carbon::parse($f['inicio']);
        $dias = $inicio->diffInDays(Carbon::parse($f['fim'])) + 1;

        return [...$f, 'inicio' => $inicio->copy()->subDays($dias)->toDateString(), 'fim' => $inicio->copy()->subDay()->toDateString()];
    }

    /**
     * @param  array<string, mixed>  $atual
     * @param  array<string, mixed>  $anterior
     * @return list<array<string, mixed>>
     */
    private function kpis(array $atual, array $anterior): array
    {
        $item = function (string $rotulo, string $chave, string $icone, string $cor, string $sufixo = '', bool $maiorMelhor = true, ?string $ajuda = null) use ($atual, $anterior) {
            $valor = $atual[$chave];
            $antes = $anterior[$chave];
            $delta = $valor !== null && $antes !== null ? round($valor - $antes, 1) : null;

            return [
                'rotulo' => $rotulo,
                'valor' => $valor === null ? '—' : $this->formatar($valor).$sufixo,
                'delta' => $delta,
                'delta_texto' => $delta === null ? null : ($delta > 0 ? '+' : '').$this->formatar($delta).$sufixo,
                'melhorou' => $delta === null || $delta == 0 ? null : (($delta > 0) === $maiorMelhor),
                'icone' => $icone,
                'cor' => $cor,
                'ajuda' => $ajuda,
            ];
        };

        return [
            $item('Cobertura dos contratos', 'cobertura', 'flaticon-381-target', 'primary', '%', true, $atual['cobertas'].' de '.$atual['previstas'].' previstas'),
            $item('Visitas realizadas', 'realizadas', 'flaticon-381-success-1', 'success', '', true, 'Por data de conclusão'),
            $item('Tempo médio de execução', 'tempo_medio_min', 'flaticon-381-stopwatch', 'info', ' min', false, 'Visitas realizadas'),
            $item('SKUs trabalhados', 'skus_trabalhados', 'flaticon-381-box-1', 'primary', '', true, 'Sem contar rupturas'),
            $item('Rupturas registradas', 'rupturas', 'flaticon-381-warning-1', 'danger', '', false, 'SKUs em falta nas visitas'),
            $item('Impedimentos de acesso', 'impedimentos', 'flaticon-381-forbidden', 'warning', '', false, 'Visitas em atenção'),
            $item('Visitas não executadas', 'nao_executadas', 'flaticon-381-error', 'danger', '', false, 'Agenda do período'),
            $item('Visitas na agenda', 'visitas', 'flaticon-381-calendar-1', 'info', '', true, 'Total no período'),
        ];
    }

    private function formatar(float|int $valor): string
    {
        return number_format((float) $valor, floor((float) $valor) == (float) $valor ? 0 : 1, ',', '.');
    }

    /**
     * Visitas da agenda por dia (ou por semana em períodos longos), por situação.
     *
     * @return array{categorias: list<string>, series: list<array{nome: string, dados: list<int>}>, agrupamento: string}
     */
    private function tendencia($agenda, array $f): array
    {
        $inicio = Carbon::parse($f['inicio']);
        $fim = Carbon::parse($f['fim']);
        $porSemana = $inicio->diffInDays($fim) > 62;

        $linhas = Visita::whereIn('visitas.id', $agenda)
            ->selectRaw('DATE(visitas.data) as dia, visitas.status as situacao, COUNT(*) as total')
            ->groupByRaw('DATE(visitas.data), visitas.status')->get();

        $balde = fn (Carbon $dia) => $porSemana ? $dia->copy()->startOfWeek()->toDateString() : $dia->toDateString();

        $chaves = [];
        for ($d = $inicio->copy(); $d->lte($fim); $d->addDay()) {
            $chaves[$balde($d)] = ['realizada' => 0, 'em_atencao' => 0, 'nao_executada' => 0, 'pendente' => 0];
        }
        foreach ($linhas as $linha) {
            $chave = $balde(Carbon::parse($linha->dia));
            $grupo = in_array($linha->situacao, ['previsto', 'em_andamento'], true) ? 'pendente' : $linha->situacao;
            $chaves[$chave][$grupo] = ($chaves[$chave][$grupo] ?? 0) + (int) $linha->total;
        }

        return [
            'agrupamento' => $porSemana ? 'semana' : 'dia',
            'categorias' => array_map(fn ($d) => Carbon::parse($d)->format('d/m'), array_keys($chaves)),
            'series' => [
                ['nome' => 'Realizadas', 'dados' => array_column($chaves, 'realizada')],
                ['nome' => 'Em atenção', 'dados' => array_column($chaves, 'em_atencao')],
                ['nome' => 'Não executadas', 'dados' => array_column($chaves, 'nao_executada')],
                ['nome' => 'Pendentes', 'dados' => array_column($chaves, 'pendente')],
            ],
        ];
    }

    /** @return array{categorias: list<string>, dados: list<int>, total: int} */
    private function estoque($execucoes): array
    {
        $contagem = VisitaSku::whereIn('visita_id', $execucoes)
            ->selectRaw('situacao_estoque as situacao, COUNT(*) as total')->groupBy('situacao_estoque')->pluck('total', 'situacao');

        $rotulos = ['tem_estoque' => 'Tem estoque', 'somente_gondola' => 'Somente na gôndola', 'ruptura' => 'Ruptura'];
        $dados = array_map(fn ($chave) => (int) ($contagem[$chave] ?? 0), array_keys($rotulos));

        return ['categorias' => array_values($rotulos), 'dados' => $dados, 'total' => array_sum($dados)];
    }

    /** @return Collection<int, object{codigo: string, descricao: string, total: int}> */
    private function topSkus($execucoes, bool $rupturas): Collection
    {
        return VisitaSku::whereIn('visita_id', $execucoes)
            ->where('situacao_estoque', $rupturas ? '=' : '!=', 'ruptura')
            ->join('skus', 'skus.id', '=', 'visita_skus.sku_id')
            ->selectRaw('skus.id as id, skus.codigo as codigo, skus.descricao as descricao, COUNT(*) as total')
            ->groupBy('skus.id', 'skus.codigo', 'skus.descricao')
            ->orderByDesc('total')->orderBy('skus.id')->limit(8)->get();
    }

    /** @return Collection<int, object{pdv: string, total: int}> */
    private function rupturasPorPdv($execucoes): Collection
    {
        return VisitaSku::whereIn('visita_id', $execucoes)->where('situacao_estoque', 'ruptura')
            ->join('visitas', 'visitas.id', '=', 'visita_skus.visita_id')->join('pdvs', 'pdvs.id', '=', 'visitas.pdv_id')
            ->selectRaw('pdvs.id as id, pdvs.nome as nome, COUNT(*) as total')
            ->groupBy('pdvs.id', 'pdvs.nome')->orderByDesc('total')->orderBy('pdvs.id')->limit(8)->get();
    }

    /**
     * Desempenho da agenda agrupado por uma dimensão (agência, indústria, rede, região).
     *
     * @return Collection<int, object{id: int, nome: string, total: int, realizadas: int, nao_executadas: int, impedimentos: int, minutos: ?float, cobertura: float}>
     */
    private function desempenho($agenda, string $tabela, string $colunaId, string $colunaNome, \Closure $juntar): Collection
    {
        $consulta = $juntar(Visita::whereIn('visitas.id', $agenda));

        return $consulta
            ->selectRaw("{$colunaId} as id, {$colunaNome} as nome, ".$this->agregados())
            ->groupBy($colunaId, $colunaNome)->orderByDesc('realizadas')->orderBy($colunaId)->limit(15)->get()
            ->map(fn ($l) => $this->completar($l));
    }

    /** @return Collection<int, object> */
    private function pdvsCriticos($agenda): Collection
    {
        return Visita::whereIn('visitas.id', $agenda)->join('pdvs', 'pdvs.id', '=', 'visitas.pdv_id')
            ->selectRaw('pdvs.id as id, pdvs.nome as nome, pdvs.cidade as cidade, '.$this->agregados())
            ->groupBy('pdvs.id', 'pdvs.nome', 'pdvs.cidade')
            ->havingRaw("SUM(CASE WHEN visitas.status = 'nao_executada' THEN 1 ELSE 0 END) + SUM(CASE WHEN visitas.tipo_conclusao = 'impedimento' THEN 1 ELSE 0 END) > 0")
            ->orderByRaw("(SUM(CASE WHEN visitas.status = 'nao_executada' THEN 1 ELSE 0 END) + SUM(CASE WHEN visitas.tipo_conclusao = 'impedimento' THEN 1 ELSE 0 END)) DESC")
            ->orderBy('pdvs.id')->limit(8)->get()
            ->map(fn ($l) => $this->completar($l));
    }

    /** @return Collection<int, object> */
    private function porPromotor($agenda, $execucoes): Collection
    {
        $skus = VisitaSku::whereIn('visita_skus.visita_id', $execucoes)->where('situacao_estoque', '!=', 'ruptura')
            ->join('visitas', 'visitas.id', '=', 'visita_skus.visita_id')->join('roteiros', 'roteiros.id', '=', 'visitas.roteiro_id')
            ->selectRaw('roteiros.promotor_id as promotor_id, COUNT(*) as total')->groupBy('roteiros.promotor_id')->pluck('total', 'promotor_id');

        return Visita::whereIn('visitas.id', $agenda)
            ->join('roteiros', 'roteiros.id', '=', 'visitas.roteiro_id')->join('users', 'users.id', '=', 'roteiros.promotor_id')
            ->selectRaw('users.id as id, users.nome as nome, '.$this->agregados())
            ->groupBy('users.id', 'users.nome')->orderByDesc('realizadas')->orderBy('users.id')->limit(15)->get()
            ->map(function ($l) use ($skus) {
                $l = $this->completar($l);
                $l->skus = (int) ($skus[$l->id] ?? 0);

                return $l;
            });
    }

    /** @return array{categorias: list<string>, dados: list<int>} */
    private function porDiaSemana($execucoes): array
    {
        $porDia = Visita::whereIn('visitas.id', $execucoes)->where('visitas.status', 'realizada')
            ->selectRaw('DATE(visitas.concluida_em) as dia, COUNT(*) as total')->groupByRaw('DATE(visitas.concluida_em)')->pluck('total', 'dia');

        $soma = array_fill(0, 7, 0);
        foreach ($porDia as $dia => $total) {
            $soma[Carbon::parse($dia)->dayOfWeek] += (int) $total;
        }

        // Segunda a domingo.
        $ordem = [1, 2, 3, 4, 5, 6, 0];

        return [
            'categorias' => array_map(fn ($i) => self::DIAS_SEMANA[$i], $ordem),
            'dados' => array_map(fn ($i) => $soma[$i], $ordem),
        ];
    }

    private function agregados(): string
    {
        return "COUNT(*) as total,
            SUM(CASE WHEN visitas.status = 'realizada' THEN 1 ELSE 0 END) as realizadas,
            SUM(CASE WHEN visitas.status = 'nao_executada' THEN 1 ELSE 0 END) as nao_executadas,
            SUM(CASE WHEN visitas.tipo_conclusao = 'impedimento' THEN 1 ELSE 0 END) as impedimentos,
            AVG(CASE WHEN visitas.status = 'realizada' THEN visitas.duracao_segundos ELSE NULL END) / 60 as minutos";
    }

    private function completar(object $linha): object
    {
        $linha->total = (int) $linha->total;
        $linha->realizadas = (int) $linha->realizadas;
        $linha->nao_executadas = (int) $linha->nao_executadas;
        $linha->impedimentos = (int) $linha->impedimentos;
        $linha->minutos = $linha->minutos === null ? null : round((float) $linha->minutos, 1);
        $linha->cobertura = $linha->total ? round($linha->realizadas / $linha->total * 100, 1) : 0.0;

        return $linha;
    }
}
