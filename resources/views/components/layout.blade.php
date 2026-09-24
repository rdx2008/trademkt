<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $titulo ?? 'Trade' }} · {{ $marca ?? config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    @isset($nav)
        <header class="topo">
            <span class="marca">{{ $marca ?? config('app.name') }}</span>
            <nav>{{ $nav }}</nav>
        </header>
    @endisset

    <main>
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
</body>
</html>
