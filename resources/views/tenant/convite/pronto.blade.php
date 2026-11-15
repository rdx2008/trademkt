<x-layout titulo="Senha criada" :marca="tenant('nome')" :visitante="true">
    <h4 class="text-center mb-3">Tudo certo!</h4>
    @if (session('convite_painel'))
        <p class="text-center">Sua senha foi criada. Entre no painel com o seu e-mail e a senha que você acabou de criar.</p>
        <p class="text-center"><a class="botao" href="{{ route('login') }}">Entrar no painel</a></p>
    @else
        <p class="text-center">Sua senha foi criada. Agora é só abrir o aplicativo e entrar:</p>
        <ol>
            <li>Código da empresa: <strong>{{ tenant('codigo') }}</strong></li>
            <li>Entre com o seu e-mail e a senha que você acabou de criar.</li>
        </ol>
        <p class="dica text-center">Guarde a senha: se esquecer, peça um novo link à sua agência.</p>
    @endif
</x-layout>
