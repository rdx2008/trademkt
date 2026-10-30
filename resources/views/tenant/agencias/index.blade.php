<x-layout titulo="Agências" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    <div class="cabecalho">
        <h1>Agências</h1>
        @if ($podeCadastrar)
            <a class="botao" href="{{ route('agencias.create') }}">Nova agência</a>
        @endif
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
                        <td>
                            @if ($podeCadastrar)
                                <a href="{{ route('agencias.edit', $agencia) }}">Editar</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">Nenhuma agência disponível para consulta.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
