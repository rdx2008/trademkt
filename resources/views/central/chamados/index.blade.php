<x-layout titulo="Chamados" marca="Painel central">
    <x-slot:nav>@include('central.nav')</x-slot:nav>
    @push('head')<meta name="robots" content="noindex,nofollow">@endpush
    <div class="cabecalho">
        <div>
            <h1>Central de atendimento</h1>
            <p class="texto-apoio">Chamados abertos pelas empresas clientes. Os que aguardam resposta vêm primeiro.</p>
        </div>
    </div>
    <div class="acoes" style="margin-bottom:12px">
        <a class="botao {{ $filtro ? 'secundario' : '' }}" href="{{ route('central.chamados.index') }}">Todos</a>
        @foreach ($situacoes as $v => $r)<a class="botao {{ $filtro === $v ? '' : 'secundario' }}" href="{{ route('central.chamados.index', ['situacao' => $v]) }}">{{ $r }}</a>@endforeach
    </div>
    <div class="tabela-rolagem">
        <table>
            <thead><tr><th>Empresa</th><th>Assunto</th><th>Tipo</th><th>Situação</th><th>Última mensagem</th></tr></thead>
            <tbody>
                @forelse ($chamados as $c)
                    <tr>
                        <td>{{ $c->empresa->nome }}</td>
                        <td><a href="{{ route('central.chamados.show', $c->id) }}">{{ $c->assunto }}</a><div class="rotulo">{{ $c->aberto_por_nome }}</div></td>
                        <td>{{ $categorias[$c->categoria] ?? $c->categoria }}</td>
                        <td><span @class(['selo', 'alerta' => $c->situacao === 'aberto', 'ok' => $c->situacao === 'respondido', 'off' => $c->situacao === 'fechado'])>{{ $situacoes[$c->situacao] ?? $c->situacao }}</span></td>
                        <td style="white-space:nowrap">{{ $c->ultima_mensagem_em?->format('d/m/Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">Nenhum chamado.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
