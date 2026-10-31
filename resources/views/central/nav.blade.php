<a href="{{ route('central.dashboard') }}" @class(['ativo' => request()->routeIs('central.dashboard')])>Visão geral</a>
<a href="{{ route('central.clientes.index') }}" @class(['ativo' => request()->routeIs('central.clientes.index', 'central.dominios.*')])>Clientes</a>
<a href="{{ route('central.clientes.create') }}" @class(['ativo' => request()->routeIs('central.clientes.create')])>Novo cliente</a>
<a href="{{ route('central.rede.index') }}" @class(['ativo' => request()->routeIs('central.rede.*')])>Rede de agências</a>
<span class="rotulo">{{ auth('admin')->user()->nome }} · Super admin</span>
<form method="POST" action="{{ route('central.logout') }}">
    @csrf
    <button type="submit" class="link-botao">Sair</button>
</form>
