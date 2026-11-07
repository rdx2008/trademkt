<x-layout titulo="Conferir promotor" :marca="tenant('nome')" :visitante="true">
    <div style="text-align:center">
        @if ($p->foto && $p->ativo)
            <img src="{{ route('cracha.foto', $codigo) }}" alt="Foto do promotor" style="width:140px;height:140px;border-radius:50%;object-fit:cover;margin-bottom:12px">
        @endif
        <h4 class="mb-1">{{ \Illuminate\Support\Str::words($p->nome, 1, '') }}</h4>
        <p class="mb-3">Promotor da <strong>{{ tenant('nome') }}</strong></p>
        @if ($p->ativo)
            <p><span class="selo ok">Cadastro ativo</span></p>
            <p class="dica">Confira se a foto é da pessoa que está na loja.</p>
        @else
            <p><span class="selo off">Cadastro inativo</span></p>
            <p class="dica">Esta pessoa não atua mais pela agência. Avise a gerência da loja.</p>
        @endif
    </div>
</x-layout>
