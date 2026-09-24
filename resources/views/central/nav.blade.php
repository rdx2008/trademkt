<a href="{{ route('central.clientes.index') }}" @class(['ativo' => request()->routeIs('central.clientes.*')])>Clientes</a>
<form method="POST" action="{{ route('central.logout') }}">
    @csrf
    <button type="submit" class="link-botao">Sair</button>
</form>
