<x-layout titulo="Modelos de coleta" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>
    <div class="cabecalho">
        <div>
            <h1>Modelos de coleta</h1>
            <p class="texto-apoio">Formulários que o promotor responde dentro da visita, no app, mesmo sem internet: preço, validade, estoque, share e checklists.</p>
        </div>
        <a class="botao" href="{{ route('coletas.modelos.create') }}">Novo modelo em branco</a>
    </div>

    <div class="cartao">
        <h2>Começar de um modelo pronto</h2>
        <div class="acoes">
            @foreach ($partidas as $chave => $partida)
                <a class="botao secundario" href="{{ route('coletas.modelos.create', ['partida' => $chave]) }}">{{ $partida['nome'] }}</a>
            @endforeach
        </div>
    </div>

    <div class="tabela-rolagem">
        <table>
            <thead><tr><th>Modelo</th><th>Tipo</th><th>Indústria</th><th>Campos</th><th>Versão</th><th>Situação</th><th></th></tr></thead>
            <tbody>
                @forelse ($modelos as $m)
                    <tr>
                        <td>{{ $m->nome }}</td>
                        <td>{{ $m->rotuloTipo() }}</td>
                        <td>{{ $m->industria->nome }}</td>
                        <td>{{ count($m->campos) }}{{ $m->porSku() ? ' (por produto)' : '' }}</td>
                        <td>{{ $m->versao }}</td>
                        <td><span @class(['selo', 'ok' => $m->ativo, 'off' => ! $m->ativo])>{{ $m->ativo ? 'Ativo' : 'Inativo' }}</span></td>
                        <td style="white-space:nowrap">
                            <a href="{{ route('coletas.modelos.edit', $m->id) }}">Editar</a>
                            <form method="POST" action="{{ route('coletas.modelos.ativo', $m->id) }}" style="display:inline">
                                @csrf @method('PATCH')
                                <input type="hidden" name="ativo" value="{{ $m->ativo ? 0 : 1 }}">
                                <button type="submit" class="link-botao">{{ $m->ativo ? 'Desativar' : 'Ativar' }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">Nenhum modelo ainda. Comece de um modelo pronto acima.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
