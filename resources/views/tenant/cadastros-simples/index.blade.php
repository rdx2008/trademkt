<x-layout :titulo="$rotulos['plural']" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    <div class="cabecalho">
        <h1>{{ $rotulos['plural'] }}</h1>
        <a class="botao" href="{{ route($rota.'.create') }}">{{ $rotulos['novo'] }}</a>
    </div>

    <form method="GET" class="filtros">
        <input type="search" name="busca" value="{{ request('busca') }}" placeholder="Nome">
        <button type="submit" class="botao secundario">Filtrar</button>
    </form>

    <div class="tabela-rolagem">
        <table>
            <thead>
                <tr><th>Nome</th><th>PDVs</th><th>Situação</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($itens as $item)
                    <tr>
                        <td>{{ $item->nome }}</td>
                        <td>{{ $item->pdvs_count }}</td>
                        <td><span @class(['selo', 'ok' => $item->ativo, 'off' => ! $item->ativo])>{{ $item->ativo ? 'Ativa' : 'Inativa' }}</span></td>
                        <td><a href="{{ route($rota.'.edit', $item->id) }}">Editar</a></td>
                    </tr>
                @empty
                    <tr><td colspan="4">Nenhum cadastro.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="paginacao">{{ $itens->links('pagination::simple-default') }}</div>
</x-layout>
