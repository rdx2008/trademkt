@php($eu = auth()->user())
<a href="{{ route('dashboard') }}" @class(['ativo' => request()->routeIs('dashboard')])>Painel</a>
@if ($eu->temPerfil(\App\Enums\Perfil::AdminInstalacao, \App\Enums\Perfil::AdminAgencia, \App\Enums\Perfil::GerenteTrade))
    <a href="{{ route('usuarios.index') }}" @class(['ativo' => request()->routeIs('usuarios.*')])>Usuários</a>
@endif
@if ($eu->temPerfil(\App\Enums\Perfil::AdminInstalacao, \App\Enums\Perfil::AdminAgencia, \App\Enums\Perfil::Supervisor, \App\Enums\Perfil::GerenteTrade, \App\Enums\Perfil::Representante, \App\Enums\Perfil::GerentePdv))
    <a href="{{ route('pdvs.index') }}" @class(['ativo' => request()->routeIs('pdvs.*')])>PDVs</a>
@endif
@if ($eu->temPerfil(\App\Enums\Perfil::AdminInstalacao, \App\Enums\Perfil::AdminAgencia, \App\Enums\Perfil::Supervisor, \App\Enums\Perfil::GerenteTrade, \App\Enums\Perfil::Representante))
    <a href="{{ route('skus.index') }}" @class(['ativo' => request()->routeIs('skus.*')])>Catálogo</a>
@endif
@if ($eu->temPerfil(\App\Enums\Perfil::AdminInstalacao, \App\Enums\Perfil::AdminAgencia))
    <a href="{{ route('regioes.index') }}" @class(['ativo' => request()->routeIs('regioes.*')])>Regiões</a>
    <a href="{{ route('redes.index') }}" @class(['ativo' => request()->routeIs('redes.*')])>Redes</a>
@endif
@if ($eu->temPerfil(\App\Enums\Perfil::AdminInstalacao))
    <a href="{{ route('industrias.index') }}" @class(['ativo' => request()->routeIs('industrias.*')])>Indústrias</a>
    <a href="{{ route('agencias.index') }}" @class(['ativo' => request()->routeIs('agencias.*')])>Agências</a>
@endif
<span class="rotulo">{{ $eu->nome }} · {{ $eu->perfil->label() }}</span>
<form method="POST" action="{{ route('logout') }}">
    @csrf
    <button type="submit" class="link-botao">Sair</button>
</form>
