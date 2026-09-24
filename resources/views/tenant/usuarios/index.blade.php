<x-layout titulo="Usuários" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    <div class="cabecalho">
        <h1>Usuários</h1>
        <a class="botao" href="{{ route('usuarios.create') }}">Novo usuário</a>
    </div>

    <form method="GET" class="filtros">
        <input type="search" name="busca" value="{{ request('busca') }}" placeholder="Nome ou e-mail">
        <select name="perfil">
            <option value="">Todos os perfis</option>
            @foreach ($perfis as $valor => $rotulo)
                <option value="{{ $valor }}" @selected(request('perfil') === $valor)>{{ $rotulo }}</option>
            @endforeach
        </select>
        <button type="submit" class="botao secundario">Filtrar</button>
    </form>

    <div class="tabela-rolagem">
        <table>
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Perfil</th>
                    <th>Indústria / Agência</th>
                    <th>Situação</th>
                    <th>Último acesso</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($usuarios as $u)
                    <tr>
                        <td>{{ $u->nome }}<div class="rotulo">{{ $u->email }}</div></td>
                        <td>
                            {{ $u->perfil->label() }}
                            @if ($u->tipo_vinculo)
                                <div class="rotulo">{{ $u->tipo_vinculo->label() }}</div>
                            @endif
                        </td>
                        <td>{{ $u->industria?->nome ?? $u->agencia?->nome ?? '—' }}</td>
                        <td>
                            <span @class(['selo', 'ok' => $u->ativo, 'off' => ! $u->ativo])>{{ $u->ativo ? 'Ativo' : 'Inativo' }}</span>
                        </td>
                        <td>{{ $u->ultimo_acesso_em?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td>
                            @if ($eu->podeGerenciar($u))
                                <a href="{{ route('usuarios.edit', $u) }}">Editar</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">Nenhum usuário encontrado.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="paginacao">{{ $usuarios->links('pagination::simple-default') }}</div>
</x-layout>
