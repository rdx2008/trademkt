<x-layout titulo="Criar senha" :marca="tenant('nome')" :visitante="true">
    <h4 class="text-center mb-2">Olá, {{ \Illuminate\Support\Str::words($convite->usuario->nome, 1, '') }}!</h4>
    <p class="text-center mb-4">Crie a sua senha para usar o aplicativo da <strong>{{ tenant('nome') }}</strong>.</p>

    <form method="POST" action="{{ route('convite.aceitar', $token) }}">
        @csrf
        <div class="campo">
            <label for="password">Nova senha</label>
            <input type="password" id="password" name="password" required autocomplete="new-password" autofocus>
            <div class="dica">Mínimo de 8 caracteres, com letras e números.</div>
        </div>
        <div class="campo">
            <label for="password_confirmation">Repita a senha</label>
            <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
        </div>

        @include('tenant.convite._aviso')

        <div class="campo">
            <label class="check"><input type="checkbox" name="aceite" value="1" required> Li e entendi o aviso de privacidade</label>
        </div>
        <button type="submit" class="botao" style="width:100%">Criar senha e continuar</button>
    </form>
</x-layout>
