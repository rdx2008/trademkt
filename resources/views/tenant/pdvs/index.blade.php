<x-layout titulo="PDVs" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    <div class="cabecalho">
        <h1>PDVs</h1>
        @if ($podeEditar)
            <div class="acoes">
                <a class="botao secundario" href="{{ route('pdvs.importar') }}">Importar planilha</a>
                <a class="botao" href="{{ route('pdvs.create') }}">Novo PDV</a>
            </div>
        @endif
    </div>

    <form method="GET" class="filtros">
        <input type="search" name="busca" value="{{ request('busca') }}" placeholder="Nome, CNPJ, cidade ou bairro">
        <select name="rede_id">
            <option value="">Todas as redes</option>
            @foreach ($redes as $id => $nome)
                <option value="{{ $id }}" @selected((string) request('rede_id') === (string) $id)>{{ $nome }}</option>
            @endforeach
        </select>
        <select name="regiao_id">
            <option value="">Todas as regiões</option>
            @foreach ($regioes as $id => $nome)
                <option value="{{ $id }}" @selected((string) request('regiao_id') === (string) $id)>{{ $nome }}</option>
            @endforeach
        </select>
        <select name="canal">
            <option value="">Todos os canais</option>
            @foreach ($canais as $valor => $rotulo)
                <option value="{{ $valor }}" @selected(request('canal') === $valor)>{{ $rotulo }}</option>
            @endforeach
        </select>
        <input type="text" name="uf" value="{{ request('uf') }}" placeholder="UF" maxlength="2" style="min-width: 70px; width: 70px">
        <select name="situacao">
            <option value="">Ativos e inativos</option>
            <option value="ativos" @selected(request('situacao') === 'ativos')>Só ativos</option>
            <option value="inativos" @selected(request('situacao') === 'inativos')>Só inativos</option>
        </select>
        <button type="submit" class="botao secundario">Filtrar</button>
    </form>

    <div class="tabela-rolagem">
        <table>
            <thead>
                <tr>
                    <th>PDV</th>
                    <th>Rede / canal</th>
                    <th>Cidade</th>
                    <th>Região</th>
                    <th>Check-in</th>
                    <th>Situação</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($pdvs as $pdv)
                    <tr>
                        <td>{{ $pdv->nome }}<div class="rotulo">{{ $pdv->cnpjFormatado() }}</div></td>
                        <td>{{ $pdv->rede?->nome ?? '—' }}<div class="rotulo">{{ $pdv->canal?->label() }}</div></td>
                        <td>{{ $pdv->cidade }}{{ $pdv->uf ? ' - '.$pdv->uf : '' }}<div class="rotulo">{{ $pdv->bairro }}</div></td>
                        <td>{{ $pdv->regiao?->nome ?? '—' }}</td>
                        <td>
                            @if ($pdv->temCoordenadas())
                                {{ $pdv->raio_m }} m
                            @else
                                <span class="selo alerta">Sem coordenadas</span>
                            @endif
                        </td>
                        <td><span @class(['selo', 'ok' => $pdv->ativo, 'off' => ! $pdv->ativo])>{{ $pdv->ativo ? 'Ativo' : 'Inativo' }}</span></td>
                        <td>
                            @if ($podeEditar)
                                <a href="{{ route('pdvs.edit', $pdv) }}">Editar</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">Nenhum PDV encontrado.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="paginacao">{{ $pdvs->links('pagination::simple-default') }}</div>
</x-layout>
