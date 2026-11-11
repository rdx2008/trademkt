<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $site['seo_titulo'] }}</title>
<meta name="description" content="{{ $site['seo_descricao'] }}">
@if($previa ?? false)<meta name="robots" content="noindex,nofollow">@else<link rel="canonical" href="{{ url()->current() }}">@endif
<meta property="og:type" content="website"><meta property="og:title" content="{{ $site['seo_titulo'] }}"><meta property="og:description" content="{{ $site['seo_descricao'] }}"><meta property="og:url" content="{{ url()->current() }}">
@if(!empty($site['imagens']['compartilhamento']))<meta property="og:image" content="{{ route('institucional.arquivo', $site['imagens']['compartilhamento']) }}">@endif
@if(!empty($site['imagens']['favicon']))<link rel="icon" href="{{ route('institucional.arquivo', $site['imagens']['favicon']) }}">@endif
<link rel="stylesheet" href="{{ asset('tema/vendor/bootstrap/scss/bootstrap.css') }}">
<link rel="stylesheet" href="{{ asset('css/institucional.css') }}?v=1">
<style>:root { --brand: {{ $site['cor'] }}; }</style>
</head>
<body>
<a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
@if($previa ?? false)<aside class="preview-bar">Prévia do rascunho · alterações ainda não publicadas <a href="{{ route('central.site.edit') }}">Voltar à edição</a></aside>@endif
<header class="site-header"><div class="container site-nav">
<a class="site-brand" href="{{ route('institucional.home') }}" aria-label="{{ $site['marca'] }} — início">@if(!empty($site['imagens']['logo']))<img src="{{ route('institucional.arquivo', $site['imagens']['logo']) }}" alt="{{ $site['marca'] }}" width="180" height="40">@else{{ $site['marca'] }}@endif</a>
<button class="nav-toggle" type="button" aria-label="Abrir menu" aria-expanded="false" aria-controls="site-menu"><span></span><span></span><span></span></button>
<nav id="site-menu" aria-label="Navegação principal">
@foreach(['recursos'=>'Plataforma','publicos'=>'Para quem','etapas'=>'Como funciona','contato'=>'Contato'] as $chave=>$rotulo)
@if($site['secoes'][$chave]['visivel'])<a href="{{ route('institucional.home') }}#{{ $chave }}">{{ $rotulo }}</a>@endif
@endforeach
<a class="btn btn-brand nav-cta" href="{{ $site['botao_url'] }}">{{ $site['botao'] }}</a>
</nav></div></header>
<main id="conteudo">@yield('conteudo')</main>
<footer class="site-footer container"><div class="footer-main"><div><a class="site-brand" href="{{ route('institucional.home') }}">{{ $site['marca'] }}</a><p>{{ $site['rodape'] }}</p></div><nav aria-label="Rodapé">@if($site['secoes']['recursos']['visivel'])<a href="{{ route('institucional.home') }}#recursos">Plataforma</a>@endif @if($site['secoes']['contato']['visivel'])<a href="{{ route('institucional.home') }}#contato">Contato</a>@endif <a href="{{ route('institucional.privacidade') }}">Privacidade</a></nav></div>
<div class="footer-details"><span>© {{ date('Y') }} {{ $site['marca'] }}@if(!empty($site['dados_legais'])) · {{ $site['dados_legais'] }}@endif</span><div>@foreach(['email'=>'E-mail','telefone'=>'Telefone','whatsapp'=>'WhatsApp','instagram'=>'Instagram','linkedin'=>'LinkedIn'] as $campo=>$rotulo)@if(!empty($site[$campo]))<a href="{{ $campo === 'email' ? 'mailto:'.$site[$campo] : ($campo === 'telefone' ? 'tel:'.preg_replace('/[^+0-9]/', '', $site[$campo]) : $site[$campo]) }}">{{ in_array($campo, ['email','telefone']) ? $site[$campo] : $rotulo }}</a>@endif @endforeach</div></div>
</footer>
<script src="{{ asset('js/institucional.js') }}" defer></script>
</body></html>
