<x-layout titulo="Clientes" marca="Painel central">
    <x-slot:nav>@include('central.nav')</x-slot:nav>

    @if (session('criado'))
        @php($c = session('criado'))
        <div class="aviso ok">
            <strong>{{ $c['nome'] }}</strong> criado em {{ $c['url'] }}.<br>
            Admin: {{ $c['email'] }} · Senha: <code>{{ $c['senha'] }}</code><br>
            Anote a senha agora: ela não aparece de novo. Adicione o domínio no app do Coolify para gerar o SSL.
        </div>
    @endif

    <div class="cabecalho">
        <h1>Clientes</h1>
        <a class="botao" href="{{ route('central.clientes.create') }}">Novo cliente</a>
    </div>

    <div class="tabela-rolagem">
        <table>
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Tipo</th>
                    <th>Código no app</th>
                    <th>Domínios</th>
                    <th>Situação</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($clientes as $cliente)
                    <tr>
                        <td>{{ $cliente->nome }}<div class="rotulo">{{ $cliente->id }}</div></td>
                        <td>{{ $cliente->tipo->label() }}</td>
                        <td><code>{{ $cliente->codigo }}</code></td>
                        <td>
                            @foreach ($cliente->domains as $dominio)
                                <div><a href="{{ config('trade.esquema_url') }}://{{ $dominio->domain }}" target="_blank" rel="noopener">{{ $dominio->domain }}</a></div>
                            @endforeach
                        </td>
                        <td>
                            <span @class(['selo', 'ok' => $cliente->ativo, 'off' => ! $cliente->ativo])>
                                {{ $cliente->ativo ? 'Ativo' : 'Suspenso' }}
                            </span>
                        </td>
                        <td>
                            <form method="POST" action="{{ route('central.clientes.ativo', $cliente) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="link-botao"
                                    onclick="return confirm('{{ $cliente->ativo ? 'Suspender o acesso de todos os usuários deste cliente?' : 'Reativar este cliente?' }}')">
                                    {{ $cliente->ativo ? 'Suspender' : 'Reativar' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">Nenhum cliente ainda.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
