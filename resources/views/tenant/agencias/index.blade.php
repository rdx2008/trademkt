<x-layout titulo="Agências" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    <div class="cabecalho">
        <h1>Agências</h1>
        <a class="botao" href="{{ route('agencias.create') }}">Nova agência</a>
    </div>

    <div class="tabela-rolagem">
        <table>
            <thead>
                <tr><th>Nome</th><th>CNPJ</th><th>Usuários</th><th>Situação</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($agencias as $agencia)
                    <tr>
                        <td>{{ $agencia->nome }}</td>
                        <td>{{ $agencia->cnpj ?? '—' }}</td>
                        <td>{{ $agencia->usuarios_count }}</td>
                        <td><span @class(['selo', 'ok' => $agencia->ativo, 'off' => ! $agencia->ativo])>{{ $agencia->ativo ? 'Ativa' : 'Inativa' }}</span></td>
                        <td><a href="{{ route('agencias.edit', $agencia) }}">Editar</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5">Nenhuma agência cadastrada.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
