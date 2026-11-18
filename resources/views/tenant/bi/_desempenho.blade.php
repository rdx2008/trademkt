{{-- Desempenho da agenda por dimensão: gráfico empilhado + tabela. Vars: $titulo, $dica (opcional), $linhas, $coluna --}}
<div class="card">
    <div class="card-header border-0 pb-0">
        <div><h4 class="card-title mb-1">{{ $titulo }}</h4>@isset($dica)<p class="rotulo mb-0">{{ $dica }}</p>@endisset</div>
    </div>
    <div class="card-body">
        @if ($linhas->isEmpty())
            <p class="text-muted mb-0">Sem visitas no período e filtros escolhidos.</p>
        @else
            <x-grafico :config="[
                'tipo' => 'barra', 'empilhado' => true, 'altura' => max(150, $linhas->count() * 34 + 70),
                'cores' => ['#27ae60', '#dc3545', '#f5a623'],
                'categorias' => $linhas->pluck('nome'),
                'series' => [
                    ['nome' => 'Realizadas', 'dados' => $linhas->pluck('realizadas')],
                    ['nome' => 'Não executadas', 'dados' => $linhas->pluck('nao_executadas')],
                    ['nome' => 'Impedimentos', 'dados' => $linhas->pluck('impedimentos')],
                ],
            ]" />
            <div class="tabela-rolagem mt-3">
                <table>
                    <thead><tr><th>{{ $coluna }}</th><th class="text-end">Visitas</th><th style="min-width:9rem">Cobertura</th><th class="text-end">Tempo médio</th></tr></thead>
                    <tbody>
                        @foreach ($linhas as $l)
                            <tr>
                                <td>{{ $l->nome }}</td>
                                <td class="text-end">{{ $l->total }}</td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress flex-grow-1" style="height:6px" role="progressbar" aria-valuenow="{{ $l->cobertura }}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar {{ $l->cobertura >= 80 ? 'bg-success' : ($l->cobertura >= 60 ? 'bg-warning' : 'bg-danger') }}" style="width: {{ $l->cobertura }}%"></div></div>
                                        <small>{{ number_format($l->cobertura, 1, ',', '.') }}%</small>
                                    </div>
                                </td>
                                <td class="text-end">{{ $l->minutos === null ? '—' : number_format($l->minutos, 0, ',', '.').' min' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
