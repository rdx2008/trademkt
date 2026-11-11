@extends('institucional.layout')
@section('conteudo')
<article class="container privacy-page"><a href="{{ route('institucional.home') }}">← Voltar ao início</a><h1>Política de privacidade</h1><div class="privacy-text">{{ $site['privacidade'] }}</div></article>
@endsection
