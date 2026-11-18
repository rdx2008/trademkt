<x-layout :titulo="$chamado->assunto" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>
    <div class="cabecalho">
        <div>
            <h1>{{ $chamado->assunto }}</h1>
            <p class="texto-apoio">{{ $categorias[$chamado->categoria] ?? $chamado->categoria }} · {{ $situacoes[$chamado->situacao] ?? $chamado->situacao }}</p>
        </div>
        <a class="botao secundario" href="{{ route('atendimento.index') }}">Voltar</a>
    </div>

    @foreach ($chamado->mensagens as $m)
        <div class="cartao" style="{{ $m->autor === 'fornecedor' ? 'border-left:4px solid var(--cor-principal, #4a6cf7)' : '' }}">
            <div class="rotulo">{{ $m->autor === 'fornecedor' ? 'Fornecedor' : 'Você' }} · {{ $m->autor_nome }} · {{ $m->created_at?->format('d/m/Y H:i') }}</div>
            <p style="white-space:pre-line">{{ $m->texto }}</p>
        </div>
    @endforeach

    @if ($chamado->situacao !== 'fechado')
        <form method="POST" action="{{ route('atendimento.responder', $chamado->id) }}" class="cartao">
            @csrf
            <div class="campo"><label for="mensagem">Responder</label><textarea id="mensagem" name="mensagem" rows="3" maxlength="5000" required></textarea>@error('mensagem')<div class="erro">{{ $message }}</div>@enderror</div>
            <button type="submit" class="botao">Enviar</button>
        </form>
        <form method="POST" action="{{ route('atendimento.fechar', $chamado->id) }}">@csrf @method('PATCH')<button type="submit" class="link-botao" onclick="return confirm('Fechar este chamado?')">Fechar chamado</button></form>
    @else
        <p class="dica">Chamado fechado. Para continuar a conversa, abra um novo chamado.</p>
    @endif
</x-layout>
