<x-layout :titulo="'Redistribuir visitas de '.$p->nome" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>
    <div class="cabecalho">
        <div>
            <h1>Redistribuir visitas</h1>
            <p class="texto-apoio">{{ $p->nome }} · de {{ \Illuminate\Support\Carbon::parse($inicio)->format('d/m/Y') }} a {{ \Illuminate\Support\Carbon::parse($fim)->format('d/m/Y') }}</p>
        </div>
        <a class="botao secundario" href="{{ route('promotores.ficha', $p->id) }}">Voltar à ficha</a>
    </div>

    <div class="cartao">
        <p class="dica">Só visitas previstas, de hoje em diante. Cada visita vai para quem está ativo, habilitado na indústria e sem afastamento no dia: primeiro quem tem a cidade base do PDV, depois quem tem menos visitas naquele dia. Sem substituto, a visita continua com o promotor.</p>
        <div class="tabela-rolagem">
            <table>
                <thead><tr><th>Dia</th><th>PDV</th><th>Indústria</th><th>Passa para</th></tr></thead>
                <tbody>
                    @forelse ($linhas as $l)
                        <tr>
                            <td style="white-space:nowrap">{{ \Illuminate\Support\Carbon::parse($l['data'])->format('d/m/Y') }}</td>
                            <td>{{ $l['pdv'] }}@if ($l['cidade'])<div class="rotulo">{{ $l['cidade'] }}</div>@endif</td>
                            <td>{{ $l['industria'] }}</td>
                            <td>@if ($l['novo_nome']) {{ $l['novo_nome'] }} @else <span class="selo alerta">Sem substituto</span> @endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">Nenhuma visita prevista atribuída a este promotor no período.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($linhas->whereNotNull('novo_id')->isNotEmpty())
            <form method="POST" action="{{ route('promotores.redistribuir.aplicar', $p->id) }}">
                @csrf
                <input type="hidden" name="inicio" value="{{ $inicio }}">
                <input type="hidden" name="fim" value="{{ $fim }}">
                <button type="submit" class="botao" onclick="return confirm('Passar {{ $linhas->whereNotNull('novo_id')->count() }} visita(s) para os colegas indicados?')">Confirmar redistribuição</button>
            </form>
        @endif
    </div>
</x-layout>
