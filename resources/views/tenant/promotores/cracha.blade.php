<x-layout :titulo="'Crachá de '.$p->nome" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>
    <div class="cabecalho">
        <h1>Crachá digital</h1>
        <div class="acoes">
            <a class="botao secundario" href="{{ route('promotores.ficha', $p->id) }}">Voltar à ficha</a>
            <button type="button" class="botao" onclick="window.print()">Imprimir</button>
        </div>
    </div>
    <div class="cartao" style="max-width:340px;margin:0 auto;text-align:center">
        @if ($p->foto)
            <img src="{{ route('promotores.foto', $p->id) }}" alt="" style="width:120px;height:120px;border-radius:50%;object-fit:cover">
        @endif
        <h2 style="margin:10px 0 2px">{{ $p->nome }}</h2>
        <p class="texto-apoio">Promotor · {{ tenant('nome') }}</p>
        <div id="qr" style="display:inline-block;margin:12px 0" aria-label="QR Code de verificação"></div>
        <p class="dica">Aponte a câmera para conferir se este promotor é da agência.</p>
        <p class="dica" style="word-break:break-all">{{ $url }}</p>
    </div>
    @push('scripts')
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
        <script>
            new QRCode(document.getElementById('qr'), {text: @json($url), width: 200, height: 200, correctLevel: QRCode.CorrectLevel.M});
        </script>
    @endpush
</x-layout>
