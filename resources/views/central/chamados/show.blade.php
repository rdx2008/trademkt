<x-layout :titulo="$chamado->assunto" marca="Painel central">
    <x-slot:nav>@include('central.nav')</x-slot:nav>
    @push('head')<meta name="robots" content="noindex,nofollow">@endpush
    <div class="cabecalho">
        <div>
            <h1>{{ $chamado->assunto }}</h1>
            <p class="texto-apoio">{{ $chamado->empresa->nome }} · {{ $categorias[$chamado->categoria] ?? $chamado->categoria }} · {{ $situacoes[$chamado->situacao] ?? $chamado->situacao }}@if ($chamado->aberto_por_email) · {{ $chamado->aberto_por_email }}@endif</p>
        </div>
        <a class="botao secundario" href="{{ route('central.chamados.index') }}">Voltar</a>
    </div>
    @foreach ($chamado->mensagens as $m)
        <div class="cartao">
            <div class="rotulo">{{ $m->autor === 'fornecedor' ? 'Fornecedor' : 'Empresa' }} · {{ $m->autor_nome }} · {{ $m->created_at?->format('d/m/Y H:i') }}</div>
            <p style="white-space:pre-line">{{ $m->texto }}</p>
        </div>
    @endforeach
    <form method="POST" action="{{ route('central.chamados.responder', $chamado->id) }}" class="cartao">
        @csrf
        <div class="campo"><label for="mensagem">Resposta</label><textarea id="mensagem" name="mensagem" rows="4" maxlength="5000" required></textarea>@error('mensagem')<div class="erro">{{ $message }}</div>@enderror</div>
        <div class="acoes">
            <button type="submit" name="situacao" value="respondido" class="botao">Responder</button>
            <button type="submit" name="situacao" value="fechado" class="botao secundario">Responder e fechar</button>
        </div>
    </form>
</x-layout>
