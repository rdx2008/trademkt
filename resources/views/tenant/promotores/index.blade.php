<x-layout titulo="Promotores" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>
    <div class="cabecalho">
        <div>
            <h1>Promotores</h1>
            <p class="texto-apoio">A equipe de campo da agência. Eles usam só o aplicativo; o painel é para a gestão.</p>
        </div>
        @if ($podeGerir)
            <div class="acoes">
                <a class="botao secundario" href="{{ route('promotores.importar') }}">Importar planilha</a>
                <a class="botao" href="{{ route('promotores.create') }}">Novo promotor</a>
            </div>
        @endif
    </div>

    <form method="GET" class="filtros">
        <input type="search" name="busca" value="{{ $filtros['busca'] ?? '' }}" placeholder="Nome, e-mail, telefone ou cidade">
        <select name="vinculo">
            <option value="">Todos os vínculos</option>
            @foreach ($vinculos as $vinculo)
                <option value="{{ $vinculo->value }}" @selected(($filtros['vinculo'] ?? '') === $vinculo->value)>{{ $vinculo->label() }}</option>
            @endforeach
        </select>
        <select name="situacao">
            <option value="">Todas as situações</option>
            <option value="ativo" @selected(($filtros['situacao'] ?? '') === 'ativo')>Ativos</option>
            <option value="convite" @selected(($filtros['situacao'] ?? '') === 'convite')>Convite pendente</option>
            <option value="inativo" @selected(($filtros['situacao'] ?? '') === 'inativo')>Inativos</option>
        </select>
        <button type="submit" class="botao secundario">Filtrar</button>
    </form>

    <div class="tabela-rolagem">
        <table>
            <thead>
                <tr>
                    <th>Promotor</th>
                    <th>Vínculo</th>
                    <th>Contato</th>
                    <th>Cidade base</th>
                    <th>CPF</th>
                    <th>Situação</th>
                    <th>Último acesso ao app</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($promotores as $p)
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px">
                                @if ($p->foto)
                                    <img src="{{ route('promotores.foto', $p->id) }}" alt="" loading="lazy" style="width:36px;height:36px;border-radius:50%;object-fit:cover">
                                @else
                                    <span class="avatar-iniciais" aria-hidden="true">{{ mb_strtoupper(mb_substr($p->nome, 0, 1)) }}</span>
                                @endif
                                <div>{{ $p->nome }}<div class="rotulo">{{ $p->email }}</div></div>
                            </div>
                        </td>
                        <td style="white-space:nowrap">{{ $p->tipo_vinculo?->label() ?? '—' }}</td>
                        <td style="white-space:nowrap">{{ $p->telefone ?? '—' }}</td>
                        <td>{{ $p->cidade_base ? $p->cidade_base.($p->uf_base ? ' / '.$p->uf_base : '') : '—' }}</td>
                        <td style="white-space:nowrap">{{ $p->cpfMascarado() }}</td>
                        <td>
                            @if (! $p->ativo)
                                <span class="selo off">Inativo</span>
                            @elseif (! $p->conviteAceito())
                                <span class="selo alerta">Convite pendente</span>
                            @else
                                <span class="selo ok">Ativo</span>
                            @endif
                        </td>
                        <td>{{ $p->ultimo_acesso_em?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td>@if ($podeGerir)<a href="{{ route('promotores.edit', $p->id) }}">Abrir</a>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="8">Nenhum promotor encontrado.@if ($podeGerir) <a href="{{ route('promotores.create') }}">Cadastrar o primeiro</a>.@endif</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="paginacao">{{ $promotores->links('pagination::simple-default') }}</div>
</x-layout>
