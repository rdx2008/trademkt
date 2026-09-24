<x-layout titulo="Entrar" :marca="tenant('nome')">
    <div class="entrar">
        <h1>{{ tenant('nome') }}</h1>
        <form method="POST" action="{{ route('login') }}" class="cartao">
            @csrf
            <div class="campo">
                <label for="email">E-mail</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            </div>
            <div class="campo">
                <label for="password">Senha</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <div class="campo">
                <label class="check"><input type="checkbox" name="lembrar" value="1"> Manter conectado</label>
            </div>
            <button class="botao" type="submit">Entrar</button>
        </form>
    </div>
</x-layout>
