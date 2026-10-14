<x-layout titulo="Catálogo" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    <div class="cabecalho">
        <h1>Catálogo de SKUs</h1>
        @if ($podeCadastrar)
            <a class="botao" href="{{ route('skus.create') }}">Novo SKU</a>
        @endif
    </div>

    <form method="GET" class="filtros">
        <input type="search" name="busca" value="{{ request('busca') }}" placeholder="Código, descrição ou GTIN">
        @if ($industrias->count() > 1)
            <select name="industria_id">
                <option value="">Todas as indústrias</option>
                @foreach ($industrias as $id => $nome)
                    <option value="{{ $id }}" @selected((string) request('industria_id') === (string) $id)>{{ $nome }}</option>
                @endforeach
            </select>
        @endif
        <select name="categoria">
            <option value="">Todas as categorias</option>
            @foreach ($categorias as $categoria)
                <option value="{{ $categoria }}" @selected(request('categoria') === $categoria)>{{ $categoria }}</option>
            @endforeach
        </select>
        <select name="situacao">
            <option value="">Ativos e inativos</option>
            <option value="ativos" @selected(request('situacao') === 'ativos')>Só ativos</option>
            <option value="inativos" @selected(request('situacao') === 'inativos')>Só inativos</option>
        </select>
        <label class="check"><input type="checkbox" name="alerta" value="gtin" @checked(request('alerta') === 'gtin')> Só com GTIN a revisar</label>
        <button type="submit" class="botao secundario">Filtrar</button>
    </form>

    <div class="tabela-rolagem">
        <table>
            <thead>
                <tr>
                    <th></th>
                    <th>Código</th>
                    <th>Descrição</th>
                    @if ($industrias->count() > 1)<th>Indústria</th>@endif
                    <th>Embalagens (GTIN)</th>
                    <th>Situação</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($skus as $sku)
                    <tr>
                        <td>
                            @if ($sku->foto)
                                <img src="{{ route('skus.foto', $sku) }}" alt="" width="40" height="40" style="object-fit: contain">
                            @endif
                        </td>
                        <td>{{ $sku->codigo }}</td>
                        <td>{{ $sku->descricao }}<div class="rotulo">{{ $sku->categoria }}</div></td>
                        @if ($industrias->count() > 1)<td>{{ $sku->industria->nome }}</td>@endif
                        <td>
                            @foreach ($sku->embalagensOrdenadas() as $embalagem)
                                <div>
                                    {{ $embalagem->tipo->label() }}{{ $embalagem->quantidade > 1 ? ' c/ '.$embalagem->quantidade : '' }}:
                                    @if ($embalagem->gtin)
                                        <span @class(['selo alerta' => $embalagem->gtinComAlerta()]) @if ($embalagem->gtinComAlerta()) title="Dígito verificador errado" @endif>{{ $embalagem->gtin }}</span>
                                    @else
                                        <span class="rotulo">sem GTIN</span>
                                    @endif
                                </div>
                            @endforeach
                        </td>
                        <td><span @class(['selo', 'ok' => $sku->ativo, 'off' => ! $sku->ativo])>{{ $sku->ativo ? 'Ativo' : 'Inativo' }}</span></td>
                        <td>
                            @if ($sku->podeSerEditadoPor($eu))
                                <a href="{{ route('skus.edit', $sku) }}">Editar</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">Nenhum SKU encontrado.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="paginacao">{{ $skus->links('pagination::simple-default') }}</div>
</x-layout>
