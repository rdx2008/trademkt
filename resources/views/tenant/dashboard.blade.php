<x-layout titulo="Painel" :marca="$cliente->nome">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    <h1>Olá, {{ $usuario->nome }}</h1>

    @if ($contagem)
        <div class="grade">
            @foreach ($contagem as $rotulo => $numero)
                <div class="cartao">
                    <div class="numero">{{ $numero }}</div>
                    <div class="rotulo">{{ $rotulo }}</div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="cartao">
        <p><strong>Perfil:</strong> {{ $usuario->perfil->label() }}</p>
        @if ($usuario->industria)
            <p><strong>Indústria:</strong> {{ $usuario->industria->nome }}</p>
        @endif
        @if ($usuario->agencia)
            <p><strong>Agência:</strong> {{ $usuario->agencia->nome }}</p>
        @endif
        <p class="rotulo">Visitas, rupturas e indicadores entram nas próximas fases.</p>
    </div>
</x-layout>
