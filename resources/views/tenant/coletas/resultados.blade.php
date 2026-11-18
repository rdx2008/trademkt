<x-layout titulo="Resultados das coletas" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>
    <div class="cabecalho">
        <div>
            <h1>Resultados das coletas</h1>
            <p class="texto-apoio">O que os promotores coletaram nas lojas: preço, validade, estoque e share. Os relatórios usam os campos que têm "papel" definido no modelo.</p>
        </div>
        <div class="acoes">
            <a class="botao secundario" href="{{ route('coletas.resultados.csv', array_merge($filtros, ['tipo' => $tipo])) }}">Baixar CSV</a>
            <form method="POST" action="{{ route('exportacoes.store') }}">
                @csrf
                <input type="hidden" name="tipo" value="coletas">
                <input type="hidden" name="relatorio" value="{{ $tipo }}">
                @foreach ($filtros as $k => $val)@if (is_scalar($val) && $val !== '')<input type="hidden" name="{{ $k }}" value="{{ $val }}">@endif @endforeach
                <button type="submit" class="botao secundario">Gerar em segundo plano</button>
            </form>
        </div>
    </div>

    <div class="acoes" style="margin-bottom:12px">
        @foreach ($tipos as $chave => $rotulo)
            <a class="botao {{ $tipo === $chave ? '' : 'secundario' }}" href="{{ route('coletas.resultados', array_merge($filtros, ['tipo' => $chave])) }}">{{ $rotulo }}</a>
        @endforeach
    </div>

    <form method="GET" class="cartao" action="{{ route('coletas.resultados') }}">
        <input type="hidden" name="tipo" value="{{ $tipo }}">
        <div class="linha">
            <div class="campo"><label for="inicio">De</label><input id="inicio" type="date" name="inicio" value="{{ $filtros['inicio'] }}" required></div>
            <div class="campo"><label for="fim">Até</label><input id="fim" type="date" name="fim" value="{{ $filtros['fim'] }}" required></div>
            <div class="campo"><label for="rede_id">Rede</label>
                <select id="rede_id" name="rede_id"><option value="">Todas</option>@foreach ($redes as $id => $nome)<option value="{{ $id }}" @selected((int) $filtros['rede_id'] === $id)>{{ $nome }}</option>@endforeach</select>
            </div>
            <div class="campo"><label for="cidade">Cidade</label>
                <select id="cidade" name="cidade"><option value="">Todas</option>@foreach ($cidades as $c)<option value="{{ $c }}" @selected($filtros['cidade'] === $c)>{{ $c }}</option>@endforeach</select>
            </div>
            @if ($tipo === 'validade')
                <div class="campo"><label for="dias">Vence em até (dias)</label><input id="dias" type="number" name="dias" min="1" max="365" value="{{ $filtros['dias'] }}"></div>
            @endif
        </div>
        <button type="submit" class="botao secundario">Filtrar</button>
    </form>

    @if ($relatorio['resumo'])
        <div class="cartao">
            @foreach ($relatorio['resumo'] as [$rotulo, $valor])
                <div>{{ $rotulo }}@if ($valor !== null): <strong>{{ $valor }}</strong>@endif</div>
            @endforeach
        </div>
    @endif

    <div class="tabela-rolagem">
        <table>
            <thead><tr>@foreach ($relatorio['colunas'] as $c)<th>{{ $c }}</th>@endforeach</tr></thead>
            <tbody>
                @forelse ($relatorio['linhas'] as $linha)
                    <tr>@foreach ($linha as $valor)<td>{{ $valor ?? '—' }}</td>@endforeach</tr>
                @empty
                    <tr><td colspan="{{ count($relatorio['colunas']) }}">Nenhum dado de {{ mb_strtolower($tipos[$tipo]) }} neste período. Crie um modelo de coleta com os papéis certos em Cadastros → Modelos de coleta.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
