{{-- Atendimento de demandas (pedidos de visita e avisos de ruptura). Usa $bi['demandas']. --}}
<div class="card grafico-card">
    <div class="card-header border-0 pb-0"><div><h4 class="card-title mb-1">Atendimento de demandas</h4><p class="rotulo mb-0">Pedidos de visita e avisos de ruptura</p></div></div>
    <div class="card-body">
        <x-grafico :config="['tipo' => 'radial', 'altura' => 240, 'sufixo' => '%', 'formato' => 'decimal', 'cores' => ['#222fb9'], 'categorias' => ['No prazo'], 'series' => [['nome' => 'No prazo', 'dados' => [$bi['demandas']['percentual_no_prazo'] ?? 0]]]]" />
        <ul class="rank-lista mt-2">
            <li><span>Demandas abertas</span><strong class="ms-auto">{{ $bi['demandas']['abertas'] }}</strong></li>
            <li><span>Atendidas no prazo</span><strong class="ms-auto text-success">{{ $bi['demandas']['no_prazo'] }}</strong></li>
            <li><span>Atrasadas</span><strong class="ms-auto text-danger">{{ $bi['demandas']['atrasadas'] }}</strong></li>
            <li><span>Relatos de ruptura recebidos</span><strong class="ms-auto">{{ $bi['demandas']['relatos_ruptura'] }}</strong></li>
        </ul>
    </div>
</div>
