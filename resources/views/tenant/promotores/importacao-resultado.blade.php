<x-layout titulo="Resultado da importação" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>
    <div class="cabecalho">
        <h1>Resultado da importação</h1>
        <a class="botao secundario" href="{{ route('promotores.index') }}">Ir para a lista</a>
    </div>

    <div class="cartao">
        <p><strong>{{ count($criados) }}</strong> promotor(es) cadastrado(s) · <strong>{{ count($erros) }}</strong> linha(s) com erro, não gravada(s).</p>
        @if ($criados)
            <p class="dica">Os links de convite abaixo não aparecem de novo. Baixe a planilha e envie a cada promotor.</p>
            <a class="botao" href="{{ route('promotores.importar.links') }}">Baixar planilha com os links</a>
        @endif
    </div>

    @if ($criados)
        <div class="cartao">
            <h2>Cadastrados</h2>
            <div class="tabela-rolagem">
                <table>
                    <thead><tr><th>Linha</th><th>Nome</th><th>E-mail</th><th>Link do convite</th></tr></thead>
                    <tbody>
                        @foreach ($criados as $c)
                            <tr><td>{{ $c['linha'] }}</td><td>{{ $c['nome'] }}</td><td>{{ $c['email'] }}</td><td><input type="text" readonly value="{{ $c['link'] }}" onfocus="this.select()" style="min-width:260px"></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($erros)
        <div class="cartao">
            <h2>Linhas com erro</h2>
            <div class="tabela-rolagem">
                <table>
                    <thead><tr><th>Linha</th><th>Nome</th><th>O que corrigir</th></tr></thead>
                    <tbody>
                        @foreach ($erros as $e)
                            <tr><td>{{ $e['linha'] }}</td><td>{{ $e['nome'] ?: '—' }}</td><td>{{ implode(' ', $e['motivos']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="dica">Corrija essas linhas na planilha e importe de novo: as já cadastradas serão recusadas como "já cadastrado", sem duplicar.</p>
        </div>
    @endif
</x-layout>
