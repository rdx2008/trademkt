<x-layout titulo="Central de atendimento" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>
    <div class="cabecalho">
        <div>
            <h1>Central de atendimento</h1>
            <p class="texto-apoio">Fale com o fornecedor do sistema: dúvidas, problemas e sugestões. A resposta aparece aqui.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('atendimento.store') }}" class="cartao">
        @csrf
        <h2>Novo chamado</h2>
        <div class="linha">
            <div class="campo"><label for="assunto">Assunto</label><input id="assunto" name="assunto" value="{{ old('assunto') }}" maxlength="150" required>@error('assunto')<div class="erro">{{ $message }}</div>@enderror</div>
            <div class="campo"><label for="categoria">Tipo</label><select id="categoria" name="categoria">@foreach ($categorias as $v => $r)<option value="{{ $v }}" @selected(old('categoria') === $v)>{{ $r }}</option>@endforeach</select></div>
        </div>
        <div class="campo"><label for="mensagem">Mensagem</label><textarea id="mensagem" name="mensagem" rows="4" maxlength="5000" required>{{ old('mensagem') }}</textarea>@error('mensagem')<div class="erro">{{ $message }}</div>@enderror</div>
        <button type="submit" class="botao">Abrir chamado</button>
    </form>

    <div class="tabela-rolagem">
        <table>
            <thead><tr><th>Assunto</th><th>Tipo</th><th>Situação</th><th>Última mensagem</th></tr></thead>
            <tbody>
                @forelse ($chamados as $c)
                    <tr>
                        <td><a href="{{ route('atendimento.show', $c->id) }}">{{ $c->assunto }}</a><div class="rotulo">Aberto por {{ $c->aberto_por_nome }}</div></td>
                        <td>{{ $categorias[$c->categoria] ?? $c->categoria }}</td>
                        <td><span @class(['selo', 'alerta' => $c->situacao === 'aberto', 'ok' => $c->situacao === 'respondido', 'off' => $c->situacao === 'fechado'])>{{ $situacoes[$c->situacao] ?? $c->situacao }}</span></td>
                        <td style="white-space:nowrap">{{ $c->ultima_mensagem_em?->format('d/m/Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Nenhum chamado ainda.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
