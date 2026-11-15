@extends('institucional.layout')
@section('conteudo')
<article class="container privacy-page">
    <a href="{{ route('institucional.home') }}">← Voltar ao início</a>
    <h1>App do promotor</h1>
    @if ($app)
        <p>Versão {{ $app['versao'] }} · {{ number_format($app['tamanho'] / 1048576, 1, ',', '.') }} MB · publicada em {{ \Illuminate\Support\Carbon::parse($app['enviado_em'])->format('d/m/Y') }}</p>
        <p><a class="btn btn-brand" href="{{ route('aplicativo.baixar') }}" download>Baixar o app (Android)</a></p>
        <h2>Como instalar</h2>
        <ol>
            <li>Abra este endereço no celular Android e toque em <strong>Baixar o app</strong>.</li>
            <li>Ao abrir o arquivo, o Android pode pedir para <strong>permitir a instalação desta fonte</strong>: permita para o navegador que você usou.</li>
            <li>Instale, abra o app e informe o código fornecido pelo seu supervisor.</li>
        </ol>
        <p class="text-muted small">Para conferir o arquivo, o código SHA-256 desta versão é <code style="word-break:break-all">{{ $app['sha256'] }}</code>.</p>
    @else
        <p>O app estará disponível aqui em breve. Fale com o seu supervisor.</p>
    @endif
</article>
@endsection
