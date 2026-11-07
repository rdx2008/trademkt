@props(['titulo' => null, 'marca' => null])
@php
    $eu = auth()->user();
    $topicos = $eu ? \App\Support\MenuLateral::montar($eu) : [];
    $naoLidos = $eu ? \App\Models\Alerta::visiveisPara($eu)->whereNull('lida_em')->count() : 0;
    // Marca própria da instalação: nome, cor e logos (padrão: o nome do cliente e o azul do tema).
    $aparencia = \App\Services\Marca\Aparencia::atual();
    $nomeMarca = $aparencia->nome();
    $creditos = tenant()->mostrarCreditos();
    $logoClaro = $aparencia->urlLogoParaClaro();
    $logoEscuro = $aparencia->urlLogoParaEscuro();
    $icone = $aparencia->url('icone');
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $titulo ?? $nomeMarca }} · {{ $nomeMarca }}</title>
    <link rel="shortcut icon" type="image/png" href="{{ $aparencia->urlFavicon() }}">
    <link href="{{ asset('tema/vendor/bootstrap-select/dist/css/bootstrap-select.min.css') }}" rel="stylesheet">
    <link href="{{ asset('tema/css/style.css') }}" rel="stylesheet" class="main-css">
    <link href="{{ asset('css/tema-cliente.css') }}" rel="stylesheet">
    @if ($aparencia->temCorPropria())
        <link href="{{ route('marca.css', ['v' => $aparencia->versao()]) }}" rel="stylesheet">
    @endif
    @stack('head')
    <script>
        // Modo claro/escuro lembrado neste navegador; aplicado antes de pintar para não piscar.
        try { if (localStorage.getItem('trade-tema') === 'dark') { document.documentElement.setAttribute('data-theme-version', 'dark'); } } catch (e) {}
    </script>
</head>
<body>
<div id="preloader">
    <div class="sk-three-bounce">
        <div class="sk-child sk-bounce1"></div>
        <div class="sk-child sk-bounce2"></div>
        <div class="sk-child sk-bounce3"></div>
    </div>
</div>

@if (! $eu)
    {{-- Entrada: sem menu, cartão centralizado no estilo do tema. --}}
    <div class="fix-wrapper">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-5 col-md-6">
                    <div class="card mb-0 h-auto">
                        <div class="card-body">
                            <div class="text-center mb-3">
                                @if ($logoClaro)
                                    <img class="logo-entrada logo-para-claro" src="{{ $logoClaro }}" alt="{{ $nomeMarca }}">
                                    <img class="logo-entrada logo-para-escuro" src="{{ $logoEscuro }}" alt="{{ $nomeMarca }}">
                                @else
                                    <span class="marca-entrada">{{ $nomeMarca }}</span>
                                @endif
                            </div>
                            @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
                            @if ($errors->any())
                                <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach</ul></div>
                            @endif
                            {{ $slot }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@else
<div id="main-wrapper">
    <div class="nav-header">
        <a href="{{ route('dashboard') }}" class="brand-logo">
            <span class="logo-abbr">
                @if ($icone)<img src="{{ $icone }}" alt="">@else<span class="inicial-marca">{{ mb_strtoupper(mb_substr($nomeMarca, 0, 1)) }}</span>@endif
            </span>
            <span class="brand-title">
                @if ($logoEscuro)<img src="{{ $logoEscuro }}" alt="{{ $nomeMarca }}">@else{{ $nomeMarca }}@endif
            </span>
        </a>
        <div class="nav-control">
            <div class="hamburger">
                <span class="line"></span><span class="line"></span><span class="line"></span>
            </div>
        </div>
    </div>

    <div class="header">
        <div class="header-content">
            <nav class="navbar navbar-expand">
                <div class="collapse navbar-collapse justify-content-between">
                    <div class="header-left">
                        <div class="dashboard_bar">{{ $titulo ?? $nomeMarca }}</div>
                        <span class="marca-cliente d-none d-md-inline">{{ $nomeMarca }}</span>
                    </div>
                    <ul class="navbar-nav header-right">
                        <li class="nav-item dropdown notification_dropdown">
                            <a class="nav-link bell primary dz-theme-mode" href="javascript:void(0);" role="button" aria-label="Alternar modo claro e escuro" id="alternar-tema">
                                <i id="icon-light" class="fas fa-sun"></i>
                                <i id="icon-dark" class="fas fa-moon"></i>
                            </a>
                        </li>
                        <li class="nav-item dropdown notification_dropdown">
                            <a class="nav-link primary" href="{{ route('alertas.index') }}" aria-label="Alertas">
                                <i class="flaticon-381-notification"></i>
                                @if ($naoLidos)<span class="badge light text-white bg-primary rounded-circle">{{ $naoLidos > 99 ? '99+' : $naoLidos }}</span>@endif
                            </a>
                        </li>
                        <li class="nav-item dropdown header-profile">
                            <a class="nav-link" href="javascript:void(0);" role="button" data-bs-toggle="dropdown">
                                <span class="avatar-iniciais">{{ mb_strtoupper(mb_substr($eu->nome, 0, 1)) }}</span>
                                <div class="header-info ms-2 d-none d-sm-block">
                                    <span class="text-black"><strong>{{ \Illuminate\Support\Str::words($eu->nome, 2, '') }}</strong></span>
                                    <p class="fs-12 mb-0">{{ $eu->perfil->label() }}</p>
                                </div>
                            </a>
                            <div class="dropdown-menu dropdown-menu-end">
                                <span class="dropdown-item-text small text-muted">{{ $eu->email }}</span>
                                <form method="POST" action="{{ route('logout') }}" class="m-0">
                                    @csrf
                                    <button type="submit" class="dropdown-item ai-icon">
                                        <i class="flaticon-381-exit-1 text-danger"></i>
                                        <span class="ms-2">Sair</span>
                                    </button>
                                </form>
                            </div>
                        </li>
                    </ul>
                </div>
            </nav>
        </div>
    </div>

    <div class="deznav">
        <div class="deznav-scroll">
            <ul class="metismenu" id="menu">
                @foreach ($topicos as $topico)
                    @if ($topico['itens'] === [])
                        <li @class(['mm-active' => $topico['ativo']])>
                            <a class="ai-icon" href="{{ $topico['url'] }}" aria-expanded="false" @if ($topico['ativo']) aria-current="page" @endif>
                                <i class="{{ $topico['icone'] }}"></i>
                                <span class="nav-text">{{ $topico['rotulo'] }}</span>
                                @if ($topico['badge'])<span class="badge badge-sm bg-danger ms-auto">{{ $topico['badge'] }}</span>@endif
                            </a>
                        </li>
                    @else
                        <li @class(['mm-active' => $topico['ativo']])>
                            <a class="has-arrow ai-icon" href="javascript:void(0);" aria-expanded="{{ $topico['ativo'] ? 'true' : 'false' }}">
                                <i class="{{ $topico['icone'] }}"></i>
                                <span class="nav-text">{{ $topico['rotulo'] }}</span>
                            </a>
                            <ul aria-expanded="{{ $topico['ativo'] ? 'true' : 'false' }}" @class(['mm-collapse', 'mm-show' => $topico['ativo']])>
                                @foreach ($topico['itens'] as $item)
                                    <li><a href="{{ $item['url'] }}" @class(['mm-active' => $item['ativo']]) @if ($item['ativo']) aria-current="page" @endif>{{ $item['rotulo'] }}</a></li>
                                @endforeach
                            </ul>
                        </li>
                    @endif
                @endforeach
            </ul>
            @if ($creditos)
                <div class="copyright">
                    <p>Desenvolvido por <strong>Trade Promo</strong></p>
                </div>
            @endif
        </div>
    </div>

    <div class="content-body" id="conteudo">
        <div class="container-fluid">
            @if (session('status'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    {{ session('status') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
                </div>
            @endif
            @if ($errors->any())
                <div class="alert alert-danger" role="alert">
                    <ul class="mb-0">
                        @foreach ($errors->all() as $erro)
                            <li>{{ $erro }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{ $slot }}
        </div>
    </div>

    <div class="footer">
        <div class="copyright">
            <p>{{ $nomeMarca }} · {{ now()->year }}@if ($creditos) · Desenvolvido por Trade Promo @endif</p>
        </div>
    </div>
</div>
@endif

<script>var asset_url = '{{ asset('tema') }}/'; var enableSupportButton = '';</script>
<script src="{{ asset('tema/vendor/global/global.min.js') }}"></script>
<script src="{{ asset('tema/vendor/bootstrap-select/dist/js/bootstrap-select.min.js') }}"></script>
@stack('scripts-antes')
<script src="{{ asset('tema/js/custom.min.js') }}"></script>
<script src="{{ asset('tema/js/deznav-init.js') }}"></script>
<script>
    // Modo claro/escuro: o tema só troca o atributo; aqui ele é lembrado entre as páginas.
    (function () {
        function aplicar(modo) {
            document.body.setAttribute('data-theme-version', modo);
            document.documentElement.setAttribute('data-theme-version', modo);
            try { localStorage.setItem('trade-tema', modo); } catch (e) {}
            window.dispatchEvent(new CustomEvent('tema-alterado', { detail: modo }));
        }
        try { if (localStorage.getItem('trade-tema') === 'dark') { aplicar('dark'); } } catch (e) {}
        var botao = document.getElementById('alternar-tema');
        if (botao) {
            botao.addEventListener('click', function (e) {
                e.stopImmediatePropagation();
                aplicar(document.body.getAttribute('data-theme-version') === 'dark' ? 'light' : 'dark');
            }, true);
        }
    })();
</script>
@stack('scripts')
</body>
</html>
