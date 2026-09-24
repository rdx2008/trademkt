<x-layout titulo="Indústrias" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    <div class="cabecalho">
        <h1>Indústrias</h1>
        <a class="botao" href="{{ route('industrias.create') }}">Nova indústria</a>
    </div>

    <div class="tabela-rolagem">
        <table>
            <thead>
                <tr><th>Nome</th><th>CNPJ</th><th>Segmento</th><th>Usuários</th><th>Situação</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($industrias as $industria)
                    <tr>
                        <td>{{ $industria->nome }}</td>
                        <td>{{ $industria->cnpj ?? '—' }}</td>
                        <td>{{ $industria->segmento ?? '—' }}</td>
                        <td>{{ $industria->usuarios_count }}</td>
                        <td><span @class(['selo', 'ok' => $industria->ativo, 'off' => ! $industria->ativo])>{{ $industria->ativo ? 'Ativa' : 'Inativa' }}</span></td>
                        <td><a href="{{ route('industrias.edit', $industria) }}">Editar</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6">Nenhuma indústria cadastrada.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
