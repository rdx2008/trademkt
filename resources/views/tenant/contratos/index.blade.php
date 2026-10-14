<x-layout titulo="Contratos de visita" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    <div class="cabecalho">
        <h1>Contratos de visita{{ $pdvFiltrado ? ' — '.$pdvFiltrado->nome : '' }}</h1>
        @if ($podeCadastrar)
            <a class="botao" href="{{ route('contratos.create', $pdvFiltrado ? ['pdv_id' => $pdvFiltrado->id] : []) }}">Novo contrato</a>
        @endif
    </div>

    <form method="GET" class="filtros">
        @if ($pdvFiltrado)
            <input type="hidden" name="pdv_id" value="{{ $pdvFiltrado->id }}">
        @else
            <input type="search" name="busca" value="{{ request('busca') }}" placeholder="PDV ou cidade">
        @endif
        @if ($industrias->count() > 1)
            <select name="industria_id">
                <option value="">Todas as indústrias</option>
                @foreach ($industrias as $id => $nome)
                    <option value="{{ $id }}" @selected((string) request('industria_id') === (string) $id)>{{ $nome }}</option>
                @endforeach
            </select>
        @endif
        @if ($agencias->count() > 1)
            <select name="agencia_id">
                <option value="">Todas as agências</option>
                @foreach ($agencias as $id => $nome)
                    <option value="{{ $id }}" @selected((string) request('agencia_id') === (string) $id)>{{ $nome }}</option>
                @endforeach
            </select>
        @endif
        <select name="frequencia">
            <option value="">Qualquer frequência</option>
            @foreach (\App\Models\ContratoVisita::FREQUENCIAS as $f)
                <option value="{{ $f }}" @selected((string) request('frequencia') === (string) $f)>{{ $f }}x por semana</option>
            @endforeach
        </select>
        <select name="situacao">
            <option value="">Todos</option>
            <option value="vigentes" @selected(request('situacao') === 'vigentes')>Vigentes hoje</option>
            <option value="futuros" @selected(request('situacao') === 'futuros')>A começar</option>
            <option value="encerrados" @selected(request('situacao') === 'encerrados')>Encerrados ou inativos</option>
        </select>
        <button type="submit" class="botao secundario">Filtrar</button>
    </form>

    <div class="tabela-rolagem">
        <table>
            <thead>
                <tr>
                    <th>PDV</th>
                    <th>Indústria</th>
                    <th>Agência</th>
                    <th>Frequência</th>
                    <th>Vigência</th>
                    <th>Situação</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($contratos as $contrato)
                    @php($vigente = $contrato->vigente())
                    <tr>
                        <td>{{ $contrato->pdv->nome }}<div class="rotulo">{{ $contrato->pdv->cidade }}{{ $contrato->pdv->uf ? ' - '.$contrato->pdv->uf : '' }}</div></td>
                        <td>{{ $contrato->industria->nome }}</td>
                        <td>{{ $contrato->agencia->nome }}</td>
                        <td>{{ $contrato->frequencia_semana }}x por semana<div class="rotulo">{{ $contrato->diasRotulo() }}</div></td>
                        <td>{{ $contrato->inicio->format('d/m/Y') }} {{ $contrato->fim ? 'a '.$contrato->fim->format('d/m/Y') : 'sem fim' }}</td>
                        <td>
                            @if ($vigente)
                                <span class="selo ok">Vigente</span>
                            @elseif ($contrato->ativo && $contrato->inicio->isFuture())
                                <span class="selo">A começar</span>
                            @else
                                <span class="selo off">{{ $contrato->ativo ? 'Encerrado' : 'Inativo' }}</span>
                            @endif
                        </td>
                        <td>
                            @if ($contrato->podeSerEditadoPor($eu))
                                <a href="{{ route('contratos.edit', $contrato) }}">Editar</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">Nenhum contrato encontrado.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="paginacao">{{ $contratos->links('pagination::simple-default') }}</div>
</x-layout>
