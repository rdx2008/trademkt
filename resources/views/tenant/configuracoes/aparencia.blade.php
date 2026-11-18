@php
    $corAtual = $aparencia->cor();
    $paletas = ['#222fb9' => 'Azul (padrão)', '#0d6efd' => 'Azul claro', '#0f766e' => 'Verde água', '#15803d' => 'Verde', '#b45309' => 'Âmbar', '#c2410c' => 'Laranja', '#b91c1c' => 'Vermelho', '#be185d' => 'Rosa', '#6d28d9' => 'Roxo', '#334155' => 'Grafite'];
@endphp
<x-layout titulo="Aparência" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    <div class="painel-cabecalho">
        <div>
            <div class="sobretitulo">CONFIGURAÇÕES</div>
            <h1>Aparência da sua instalação</h1>
            <p class="texto-apoio">Coloque o nome, o logo e a cor da sua empresa. Todos os usuários, inclusive na tela de entrada, passam a ver a sua marca.</p>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-7">
            <form method="POST" action="{{ route('configuracoes.aparencia.salvar') }}" enctype="multipart/form-data" class="card" id="form-aparencia">
                @csrf
                @method('PUT')
                <div class="card-header border-0 pb-0"><h4 class="card-title mb-0">Marca</h4></div>
                <div class="card-body">
                    <div class="campo">
                        <label for="nome">Nome exibido</label>
                        <input type="text" id="nome" name="nome" maxlength="60" value="{{ old('nome', $aparencia->nomePersonalizado()) }}" placeholder="{{ tenant('nome') }}">
                        <div class="dica">Aparece no menu, no título da aba e na tela de entrada. Em branco, usa o nome da empresa cadastrado.</div>
                    </div>

                    <div class="campo">
                        <label for="cor-texto">Cor principal</label>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <input type="color" id="cor-seletor" value="{{ old('cor', $corAtual) }}" class="form-control-color p-0" style="width:3rem;height:2.4rem;padding:0;border:0;background:none" aria-label="Escolher cor">
                            <input type="text" id="cor-texto" name="cor" maxlength="7" value="{{ old('cor', $corAtual) }}" style="max-width:9rem" placeholder="#222fb9" autocomplete="off">
                            <span class="rotulo" id="cor-aviso"></span>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-2" role="group" aria-label="Cores prontas">
                            @foreach ($paletas as $hex => $nome)
                                <button type="button" class="paleta" data-cor="{{ $hex }}" title="{{ $nome }}" aria-label="{{ $nome }}" style="background: {{ $hex }}"></button>
                            @endforeach
                        </div>
                        <div class="dica">Vale para botões, links, menu, selos e gráficos. Cores muito claras são recusadas, para o texto continuar legível.</div>
                    </div>

                    <div class="row">
                        @foreach ([['logo_claro', 'claro', 'Logo para fundo claro', 'Usado na tela de entrada e nas áreas claras. Fundo transparente é o ideal.'], ['logo_escuro', 'escuro', 'Logo para fundo escuro', 'Usado no menu lateral colorido e no modo escuro. Costuma ser a versão branca.'], ['icone', 'icone', 'Ícone quadrado', 'Usado no menu recolhido e na aba do navegador.']] as [$campo, $tipo, $rotulo, $ajuda])
                            <div class="col-md-4 campo">
                                <label for="{{ $campo }}">{{ $rotulo }}</label>
                                @if ($aparencia->temArquivo($tipo))
                                    <div class="miniatura {{ $tipo === 'escuro' ? 'miniatura-escura' : '' }}"><img src="{{ $aparencia->url($tipo) }}" alt="{{ $rotulo }}" data-original="{{ $aparencia->url($tipo) }}" id="img-{{ $campo }}"></div>
                                    <label class="check mt-1"><input type="checkbox" name="remover[]" value="{{ $tipo }}"> Remover</label>
                                @else
                                    <div class="miniatura {{ $tipo === 'escuro' ? 'miniatura-escura' : '' }}"><img src="" alt="" id="img-{{ $campo }}" hidden></div>
                                @endif
                                <input type="file" id="{{ $campo }}" name="{{ $campo }}" accept="image/png,image/jpeg,image/webp" data-previa="img-{{ $campo }}">
                                <div class="dica">{{ $ajuda }}</div>
                            </div>
                        @endforeach
                    </div>
                    <div class="dica">PNG, JPG ou WebP de até 2 MB. As imagens são reduzidas e guardadas com segurança. SVG não é aceito.</div>
                </div>
                <div class="card-footer border-0 pt-0">
                    <button type="submit" class="botao">Salvar aparência</button>
                </div>
            </form>

            <form method="POST" action="{{ route('configuracoes.aparencia.restaurar') }}" onsubmit="return confirm('Voltar ao visual padrão e apagar logos e cor?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="botao secundario">Restaurar padrão</button>
            </form>
        </div>

        <div class="col-xl-5">
            <div class="card previa-card">
                <div class="card-header border-0 pb-0"><h4 class="card-title mb-0">Prévia</h4></div>
                <div class="card-body">
                    <div class="previa" id="previa" style="--previa-cor: {{ $corAtual }}">
                        <div class="previa-menu">
                            <div class="previa-logo" id="previa-logo">{{ mb_strtoupper(mb_substr($aparencia->nome(), 0, 1)) }}</div>
                            <span></span><span></span><span></span>
                        </div>
                        <div class="previa-corpo">
                            <div class="previa-topo"><strong id="previa-nome">{{ $aparencia->nome() }}</strong></div>
                            <div class="previa-cartao">
                                <div class="mb-2">Visitas realizadas</div>
                                <div class="previa-barra"><span></span></div>
                                <div class="mt-3 d-flex gap-2"><button type="button" class="previa-botao">Salvar</button><button type="button" class="previa-botao previa-botao-vazado">Cancelar</button></div>
                            </div>
                        </div>
                    </div>
                    <p class="rotulo mt-2 mb-0">A prévia mostra a cor e o nome antes de salvar.</p>
                </div>
            </div>
        </div>
    </div>

    @push('head')
        <style>
            .paleta { width: 1.9rem; height: 1.9rem; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 0 1px #c9c9c9; cursor: pointer; }
            .paleta:focus-visible { outline: 3px solid #3978ca; outline-offset: 2px; }
            .miniatura { height: 3.4rem; display: flex; align-items: center; justify-content: center; border: 1px dashed var(--t-borda); border-radius: .4rem; margin-bottom: .4rem; background: #fff; }
            .miniatura-escura { background: #2b2f3a; }
            .miniatura img { max-height: 2.8rem; max-width: 90%; }
            .previa { display: flex; border: 1px solid var(--t-borda); border-radius: .6rem; overflow: hidden; min-height: 15rem; background: #f5f5f5; }
            .previa-menu { width: 4.2rem; background: var(--previa-cor); padding: .8rem .5rem; display: flex; flex-direction: column; align-items: center; gap: .7rem; }
            .previa-logo { width: 2.4rem; height: 2.4rem; border-radius: .5rem; background: #fff; color: var(--previa-cor); font-weight: 700; display: flex; align-items: center; justify-content: center; overflow: hidden; }
            .previa-logo img { max-width: 100%; max-height: 100%; }
            .previa-menu span { width: 1.6rem; height: .35rem; background: rgba(255, 255, 255, .55); border-radius: 1rem; }
            .previa-corpo { flex: 1; }
            .previa-topo { background: #fff; padding: .7rem 1rem; border-bottom: 1px solid var(--t-borda); }
            .previa-cartao { margin: 1rem; padding: 1rem; background: #fff; border-radius: .5rem; font-size: .85rem; color: #333; }
            .previa-barra { height: .4rem; background: #eee; border-radius: 1rem; overflow: hidden; }
            .previa-barra span { display: block; width: 70%; height: 100%; background: var(--previa-cor); }
            .previa-botao { background: var(--previa-cor); color: #fff; border: 1px solid var(--previa-cor); border-radius: .4rem; padding: .3rem .9rem; font-size: .8rem; }
            .previa-botao-vazado { background: transparent; color: var(--previa-cor); }
        </style>
    @endpush

    @push('scripts')
        <script>
            (function () {
                var seletor = document.getElementById('cor-seletor');
                var texto = document.getElementById('cor-texto');
                var aviso = document.getElementById('cor-aviso');
                var previa = document.getElementById('previa');
                var nome = document.getElementById('nome');
                var nomePrevia = document.getElementById('previa-nome');
                var logoPrevia = document.getElementById('previa-logo');
                var inicial = logoPrevia.textContent;

                function normalizar(v) {
                    v = (v || '').trim().toLowerCase().replace('#', '');
                    if (/^[0-9a-f]{3}$/.test(v)) { v = v[0] + v[0] + v[1] + v[1] + v[2] + v[2]; }
                    return /^[0-9a-f]{6}$/.test(v) ? '#' + v : null;
                }
                function luminancia(hex) {
                    return [1, 3, 5].map(function (i) {
                        var c = parseInt(hex.substr(i, 2), 16) / 255;
                        return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
                    }).reduce(function (a, c, i) { return a + c * [0.2126, 0.7152, 0.0722][i]; }, 0);
                }
                function aplicarCor(valor) {
                    var cor = normalizar(valor);
                    if (!cor) { aviso.textContent = 'Use o formato #RRGGBB.'; return; }
                    var contraste = 1.05 / (luminancia(cor) + 0.05);
                    previa.style.setProperty('--previa-cor', cor);
                    seletor.value = cor;
                    aviso.textContent = contraste < {{ \App\Services\Marca\Aparencia::CONTRASTE_MINIMO }} ? 'Cor clara demais para botões com texto branco.' : '';
                    aviso.style.color = contraste < {{ \App\Services\Marca\Aparencia::CONTRASTE_MINIMO }} ? '#b3261e' : '';
                }
                seletor.addEventListener('input', function () { texto.value = seletor.value; aplicarCor(seletor.value); });
                texto.addEventListener('input', function () { aplicarCor(texto.value); });
                document.querySelectorAll('.paleta').forEach(function (b) {
                    b.addEventListener('click', function () { texto.value = b.dataset.cor; aplicarCor(b.dataset.cor); });
                });
                nome.addEventListener('input', function () {
                    var v = nome.value.trim() || '{{ addslashes(tenant('nome')) }}';
                    nomePrevia.textContent = v;
                    if (!logoPrevia.querySelector('img')) { logoPrevia.textContent = v.charAt(0).toUpperCase(); }
                });
                document.querySelectorAll('input[type=file][data-previa]').forEach(function (campo) {
                    campo.addEventListener('change', function () {
                        var img = document.getElementById(campo.dataset.previa);
                        if (!campo.files[0]) { return; }
                        img.src = URL.createObjectURL(campo.files[0]);
                        img.hidden = false;
                        if (campo.id === 'icone') {
                            logoPrevia.innerHTML = '';
                            var copia = document.createElement('img');
                            copia.src = img.src;
                            logoPrevia.appendChild(copia);
                        }
                    });
                });
                @if ($aparencia->temArquivo('icone'))
                    logoPrevia.innerHTML = '<img src="{{ $aparencia->url('icone') }}" alt="">';
                @endif
                aplicarCor(texto.value);
            })();
        </script>
    @endpush
</x-layout>
