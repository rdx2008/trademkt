@php
    $f = $bi['filtros'];
    $ehIndustria = $bi['visao'] === 'industria';
    $dias = \Illuminate\Support\Carbon::parse($f['inicio'])->diffInDays(\Illuminate\Support\Carbon::parse($f['fim'])) + 1;
@endphp
<x-layout :titulo="$bi['titulo']" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    <div class="painel-cabecalho">
        <div>
            <div class="sobretitulo">RESULTADOS / {{ $ehIndustria ? 'INDÚSTRIA' : 'AGÊNCIA' }}</div>
            <h1>{{ $bi['titulo'] }}</h1>
            <p class="texto-apoio">
                {{ $ehIndustria
                    ? 'Cobertura da sua marca, desempenho das agências prestadoras, produtos trabalhados e rupturas.'
                    : 'Produtividade dos promotores, atendimento por indústria cliente, estoque e rupturas.' }}
                Período de {{ \Illuminate\Support\Carbon::parse($f['inicio'])->format('d/m/Y') }} a {{ \Illuminate\Support\Carbon::parse($f['fim'])->format('d/m/Y') }} ({{ $dias }} dias), comparado aos {{ $dias }} dias anteriores.
            </p>
        </div>
        <a class="btn btn-outline-primary" href="{{ route('indicadores.exportar', array_filter($f)) }}"><i class="las la-file-excel me-1"></i> Exportar Excel</a>
    </div>

    @include('tenant.indicadores.filtros', ['acao' => route('bi.index'), 'filtros' => $f])

    {{-- Indicadores com variação em relação ao período anterior --}}
    <section class="row" aria-label="Indicadores do período">
        @foreach ($bi['kpis'] as $k)
            <div class="col-xl-3 col-sm-6">
                <x-indicador :rotulo="$k['rotulo']" :valor="$k['valor']" :icone="$k['icone']" :cor="$k['cor']"
                    :ajuda="$k['ajuda']"
                    :selo="$k['delta_texto'] === null ? null : $k['delta_texto'].' vs. período anterior'"
                    :selo-cor="$k['melhorou'] === null ? 'secondary' : ($k['melhorou'] ? 'success' : 'danger')" />
            </div>
        @endforeach
    </section>

    {{-- Execução e estoque --}}
    <div class="row">
        <div class="col-xl-8">
            <div class="card grafico-card">
                <div class="card-header border-0 pb-0">
                    <div>
                        <h4 class="card-title mb-1">Execução da agenda</h4>
                        <p class="rotulo mb-0">Visitas por situação, por {{ $bi['tendencia']['agrupamento'] === 'semana' ? 'semana (início na segunda)' : 'dia' }}</p>
                    </div>
                </div>
                <div class="card-body">
                    <x-grafico :config="['tipo' => 'coluna', 'empilhado' => true, 'altura' => 320, 'cores' => ['#27ae60', '#f5a623', '#dc3545', '#9aa0a8'], 'categorias' => $bi['tendencia']['categorias'], 'series' => $bi['tendencia']['series']]" />
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card grafico-card">
                <div class="card-header border-0 pb-0">
                    <div><h4 class="card-title mb-1">Situação do estoque</h4><p class="rotulo mb-0">SKUs verificados nas visitas concluídas</p></div>
                </div>
                <div class="card-body">
                    @if ($bi['estoque']['total'] === 0)
                        <p class="text-muted">Nenhum SKU verificado no período.</p>
                    @else
                        <x-grafico :config="['tipo' => 'rosca', 'altura' => 300, 'rotuloTotal' => 'SKUs', 'cores' => ['#27ae60', '#f5a623', '#dc3545'], 'categorias' => $bi['estoque']['categorias'], 'series' => [['nome' => 'SKUs', 'dados' => $bi['estoque']['dados']]]]" />
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Visão específica --}}
    @if ($ehIndustria)
        <div class="row">
            <div class="col-xl-7">@include('tenant.bi._desempenho', ['titulo' => 'Desempenho por agência prestadora', 'dica' => 'Cobertura = visitas realizadas ÷ visitas da agenda', 'linhas' => $bi['porAgencia'], 'coluna' => 'Agência'])</div>
            <div class="col-xl-5">@include('tenant.bi._demandas')</div>
        </div>
    @else
        <div class="row">
            <div class="col-xl-7">
                <div class="card">
                    <div class="card-header border-0 pb-0"><div><h4 class="card-title mb-1">Produtividade dos promotores</h4><p class="rotulo mb-0">Visitas da agenda, SKUs trabalhados e tempo médio</p></div></div>
                    <div class="card-body">
                        @if ($bi['porPromotor']->isEmpty())
                            <p class="text-muted mb-0">Nenhuma visita atribuída a promotores no período.</p>
                        @else
                            <div class="tabela-rolagem">
                                <table>
                                    <thead><tr><th>Promotor</th><th class="text-end">Visitas</th><th class="text-end">Realizadas</th><th style="min-width:9rem">Cobertura</th><th class="text-end">SKUs</th><th class="text-end">Impedimentos</th><th class="text-end">Tempo médio</th></tr></thead>
                                    <tbody>
                                        @foreach ($bi['porPromotor'] as $l)
                                            <tr>
                                                <td>{{ $l->nome }}</td>
                                                <td class="text-end">{{ $l->total }}</td>
                                                <td class="text-end">{{ $l->realizadas }}</td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <div class="progress flex-grow-1" style="height:6px"><div class="progress-bar {{ $l->cobertura >= 80 ? 'bg-success' : ($l->cobertura >= 60 ? 'bg-warning' : 'bg-danger') }}" style="width: {{ $l->cobertura }}%"></div></div>
                                                        <small>{{ number_format($l->cobertura, 1, ',', '.') }}%</small>
                                                    </div>
                                                </td>
                                                <td class="text-end">{{ $l->skus }}</td>
                                                <td class="text-end">{{ $l->impedimentos }}</td>
                                                <td class="text-end">{{ $l->minutos === null ? '—' : number_format($l->minutos, 0, ',', '.').' min' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="card grafico-card">
                    <div class="card-header border-0 pb-0"><div><h4 class="card-title mb-1">Visitas realizadas por dia da semana</h4><p class="rotulo mb-0">Ajuda a equilibrar os roteiros</p></div></div>
                    <div class="card-body">
                        <x-grafico :config="['tipo' => 'coluna', 'altura' => 280, 'semLegenda' => true, 'cores' => ['#222fb9'], 'categorias' => $bi['porDiaSemana']['categorias'], 'series' => [['nome' => 'Realizadas', 'dados' => $bi['porDiaSemana']['dados']]]]" />
                    </div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-7">@include('tenant.bi._desempenho', ['titulo' => 'Atendimento por indústria cliente', 'dica' => 'Cobertura = visitas realizadas ÷ visitas da agenda', 'linhas' => $bi['porIndustria'], 'coluna' => 'Indústria'])</div>
            <div class="col-xl-5">@include('tenant.bi._demandas')</div>
        </div>
    @endif

    {{-- Produtos e rupturas --}}
    <div class="row">
        <div class="col-xl-4">
            <div class="card grafico-card">
                <div class="card-header border-0 pb-0"><div><h4 class="card-title mb-1">SKUs mais trabalhados</h4><p class="rotulo mb-0">Em visitas realizadas</p></div></div>
                <div class="card-body">
                    @if ($bi['topSkus']->isEmpty())<p class="text-muted mb-0">Sem produtos trabalhados no período.</p>
                    @else<x-grafico :config="['tipo' => 'barra', 'altura' => max(200, $bi['topSkus']->count() * 40 + 40), 'semLegenda' => true, 'cores' => ['#222fb9'], 'categorias' => $bi['topSkus']->pluck('descricao'), 'series' => [['nome' => 'Vezes trabalhado', 'dados' => $bi['topSkus']->pluck('total')]]]" />@endif
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card grafico-card">
                <div class="card-header border-0 pb-0"><div><h4 class="card-title mb-1">SKUs em ruptura</h4><p class="rotulo mb-0">Mais registrados nas visitas</p></div></div>
                <div class="card-body">
                    @if ($bi['topRupturasSku']->isEmpty())<p class="text-muted mb-0">Nenhuma ruptura registrada no período.</p>
                    @else<x-grafico :config="['tipo' => 'barra', 'altura' => max(200, $bi['topRupturasSku']->count() * 40 + 40), 'semLegenda' => true, 'cores' => ['#dc3545'], 'categorias' => $bi['topRupturasSku']->pluck('descricao'), 'series' => [['nome' => 'Rupturas', 'dados' => $bi['topRupturasSku']->pluck('total')]]]" />@endif
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card">
                <div class="card-header border-0 pb-0"><div><h4 class="card-title mb-1">PDVs com mais rupturas</h4><p class="rotulo mb-0">Onde o estoque falha</p></div></div>
                <div class="card-body">
                    @if ($bi['rupturasPorPdv']->isEmpty())<p class="text-muted mb-0">Nenhuma ruptura registrada no período.</p>
                    @else
                        @php($maiorRuptura = max(1, $bi['rupturasPorPdv']->max('total')))
                        <ul class="rank-lista">
                            @foreach ($bi['rupturasPorPdv'] as $i => $p)
                                <li><span class="posicao">{{ $i + 1 }}</span><div class="flex-grow-1"><div class="d-flex justify-content-between"><span>{{ $p->nome }}</span><strong>{{ $p->total }}</strong></div><div class="barra"><span style="width: {{ round($p->total / $maiorRuptura * 100) }}%; background:#dc3545"></span></div></div></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Redes, regiões e pontos de atenção --}}
    <div class="row">
        <div class="col-xl-6">@include('tenant.bi._desempenho', ['titulo' => 'Desempenho por rede de supermercados', 'linhas' => $bi['porRede'], 'coluna' => 'Rede'])</div>
        <div class="col-xl-6">@include('tenant.bi._desempenho', ['titulo' => 'Desempenho por região', 'linhas' => $bi['porRegiao'], 'coluna' => 'Região'])</div>
    </div>

    <div class="card">
        <div class="card-header border-0 pb-0"><div><h4 class="card-title mb-1">PDVs que pedem atenção</h4><p class="rotulo mb-0">Visitas não executadas e impedimentos de acesso no período</p></div></div>
        <div class="card-body">
            @if ($bi['pdvsCriticos']->isEmpty())
                <p class="text-muted mb-0">Nenhum PDV com visita não executada ou impedimento no período.</p>
            @else
                <div class="tabela-rolagem">
                    <table>
                        <thead><tr><th>PDV</th><th>Cidade</th><th class="text-end">Visitas</th><th class="text-end">Não executadas</th><th class="text-end">Impedimentos</th><th style="min-width:9rem">Cobertura</th></tr></thead>
                        <tbody>
                            @foreach ($bi['pdvsCriticos'] as $l)
                                <tr>
                                    <td>{{ $l->nome }}</td><td>{{ $l->cidade }}</td>
                                    <td class="text-end">{{ $l->total }}</td>
                                    <td class="text-end">{{ $l->nao_executadas }}</td>
                                    <td class="text-end">{{ $l->impedimentos }}</td>
                                    <td><div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1" style="height:6px"><div class="progress-bar {{ $l->cobertura >= 80 ? 'bg-success' : ($l->cobertura >= 60 ? 'bg-warning' : 'bg-danger') }}" style="width: {{ $l->cobertura }}%"></div></div><small>{{ number_format($l->cobertura, 1, ',', '.') }}%</small></div></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <p class="nota-painel">Cobertura dos contratos considera as visitas contratuais já geradas pela data prevista. Realizações, SKUs e tempo usam a data da conclusão; ruptura conta como visita realizada, mas não como SKU trabalhado. A variação compara com o período imediatamente anterior, de mesma duração.</p>
</x-layout>
