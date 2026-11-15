<x-layout titulo="App Android" marca="Trade Promo">
    <x-slot:nav>@include('central.nav')</x-slot:nav>
    @push('head')<meta name="robots" content="noindex,nofollow">@endpush
    <h1>App do promotor (Android)</h1>
    <p class="texto-apoio">O APK publicado aqui é o que a página <a href="{{ route('aplicativo.pagina') }}" target="_blank" rel="noopener">{{ route('aplicativo.pagina') }}</a> entrega. Sem loja de aplicativos: divulgue esse link aos promotores.</p>

    <div class="cartao">
        <h2>Versão publicada</h2>
        @if ($app)
            <p>Versão <strong>{{ $app['versao'] }}</strong> · {{ number_format($app['tamanho'] / 1048576, 1, ',', '.') }} MB · enviada em {{ \Illuminate\Support\Carbon::parse($app['enviado_em'])->format('d/m/Y H:i') }}</p>
            <p class="rotulo" style="word-break:break-all">SHA-256: {{ $app['sha256'] }}</p>
            <form method="POST" action="{{ route('central.aplicativo.destroy') }}" onsubmit="return confirm('Remover o APK publicado? A página de download passará a mostrar \'em breve\'.')">
                @csrf @method('DELETE')
                <button type="submit" class="link-botao">Remover APK</button>
            </form>
        @else
            <p>Nenhum APK publicado. A página de download mostra "em breve".</p>
        @endif
    </div>

    <form method="POST" action="{{ route('central.aplicativo.update') }}" enctype="multipart/form-data" class="cartao">
        @csrf
        <h2>Publicar nova versão</h2>
        <div class="campo">
            <label for="versao">Versão</label>
            <input id="versao" name="versao" value="{{ old('versao') }}" maxlength="30" required placeholder="1.0.0">
            @error('versao')<div class="erro">{{ $message }}</div>@enderror
        </div>
        <div class="campo">
            <label for="arquivo">Arquivo APK (release, até 60 MB)</label>
            <input id="arquivo" type="file" name="arquivo" accept=".apk" required>
            @error('arquivo')<div class="erro">{{ $message }}</div>@enderror
            <div class="dica">Gere com <code>flutter build apk --release --dart-define=URL_CENTRAL=...</code>. A versão nova substitui a anterior.</div>
        </div>
        <button type="submit" class="botao">Publicar</button>
    </form>
</x-layout>
