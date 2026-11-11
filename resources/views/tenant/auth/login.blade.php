<x-layout titulo="Entrar" :marca="tenant('nome')">
    <h4 class="text-center mb-4">{{ tenant('nome') }}</h4>
    <p class="text-center text-muted mb-4">Entre com seu e-mail e senha.</p>
    <form method="POST" action="{{ route('login') }}">
        @csrf
        <div class="form-group mb-4">
            <label class="form-label" for="email">E-mail</label>
            <input type="email" class="form-control" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        </div>
        <div class="form-group mb-4">
            <label class="form-label" for="password">Senha</label>
            <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
        </div>
        <div class="form-group mb-4">
            <div class="form-check custom-checkbox ms-1">
                <input type="checkbox" class="form-check-input" id="lembrar" name="lembrar" value="1">
                <label class="form-check-label" for="lembrar">Manter conectado</label>
            </div>
        </div>
        <div class="text-center">
            <button class="btn btn-primary btn-block w-100" type="submit">Entrar</button>
        </div>
    </form>
</x-layout>
