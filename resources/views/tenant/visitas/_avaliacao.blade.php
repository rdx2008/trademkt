@php($tagsFoto = \App\Models\FotoAvaliacao::TAGS)
@if ($foto->avaliacoes->isNotEmpty())
    <p class="rotulo">@if ($foto->notaMedia() !== null)Nota {{ number_format($foto->notaMedia(), 1, ',', '') }} de 5 · @endif{{ $foto->avaliacoes->count() }} avaliação(ões)</p>
    @foreach ($foto->avaliacoes as $a)
        <p class="rotulo">{{ $a->usuario_nome }}: {{ $a->nota ? str_repeat('★', $a->nota).str_repeat('☆', 5 - $a->nota) : 'sem nota' }}@if ($a->tags) · {{ collect($a->tags)->map(fn ($t) => $tagsFoto[$t] ?? $t)->implode(', ') }}@endif</p>
    @endforeach
@endif
@if (auth()->user()->temPerfil(\App\Enums\Perfil::AdminInstalacao, \App\Enums\Perfil::AdminAgencia, \App\Enums\Perfil::Supervisor, \App\Enums\Perfil::GerenteTrade, \App\Enums\Perfil::Representante))
    <details>
        <summary>Avaliar esta foto</summary>
        <form method="POST" action="{{ route('visitas.fotos.avaliar', [$visita, $foto]) }}">
            @csrf
            <label>Nota <select name="nota"><option value="">Sem nota</option>@foreach (range(1, 5) as $n)<option value="{{ $n }}">{{ str_repeat('★', $n) }} ({{ $n }})</option>@endforeach</select></label>
            @foreach ($tagsFoto as $v => $r)<label class="check"><input type="checkbox" name="tags[]" value="{{ $v }}"> {{ $r }}</label>@endforeach
            <button type="submit" class="botao secundario">Salvar avaliação</button>
        </form>
    </details>
@endif
