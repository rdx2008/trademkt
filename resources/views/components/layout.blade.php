@if (function_exists('tenancy') && tenancy()->initialized && ! in_array(request()->getHost(), config('tenancy.central_domains'), true))
    {{-- Telas dos clientes usam o tema novo; o painel do super admin (central) mantém o visual próprio. --}}
    <x-layout-cliente :titulo="$titulo ?? null" :marca="$marca ?? null">{{ $slot }}</x-layout-cliente>
@else
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $titulo ?? 'Trade' }} · {{ $marca ?? config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    @stack('head')
</head>
<body @class(['com-navegacao' => isset($nav)])>
    <a class="pular-conteudo" href="#conteudo">Pular para o conteúdo</a>
    @isset($nav)
        <header class="topo">
            <div class="marca"><span class="marca-produto">TRADE <b>PROMO</b></span><span class="marca-cliente">{{ $marca ?? config('app.name') }}</span></div>
            <button type="button" class="menu-alternar" aria-controls="menu-principal" aria-expanded="false" onclick="const aberto = this.getAttribute('aria-expanded') !== 'true'; this.setAttribute('aria-expanded', String(aberto)); document.getElementById('menu-principal').classList.toggle('aberto', aberto)">Menu <span aria-hidden="true">☰</span></button>
            <nav id="menu-principal" aria-label="Navegação principal">{{ $nav }}</nav>
            <div class="rodape-menu">Execução no ponto de venda<br><span>Trade marketing conectado.</span></div>
        </header>
    @endisset

    <main id="conteudo" tabindex="-1">
        @if (session('status'))
            <div class="aviso ok">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="aviso erro">
                <ul>
                    @foreach ($errors->all() as $erro)
                        <li>{{ $erro }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{ $slot }}
    </main>

    @stack('scripts')
</body>
</html>
@endif
