<x-layout titulo="Nova agência" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>
    <div class="cabecalho">
        <div>
            <h1>Nova agência</h1>
            <p class="texto-apoio">A agência ganha a própria instalação, onde cadastra supervisores e promotores. O vínculo com {{ tenant('nome') }} já nasce ativo.</p>
        </div>
        <a class="botao secundario" href="{{ route('agencias.index') }}">Voltar</a>
    </div>

    <form method="POST" action="{{ route('agencias.store') }}" class="cartao">
        @csrf
        <div class="campo">
            <label class="check"><input type="radio" name="tipo_agencia" value="interna" @checked(old('tipo_agencia', 'interna') === 'interna')> <strong>Agência interna</strong> de {{ tenant('nome') }}</label>
            <div class="dica">A equipe de promoção da própria indústria, organizada como agência (com CNPJ próprio). Só atende esta indústria.</div>
            <label class="check"><input type="radio" name="tipo_agencia" value="externa" @checked(old('tipo_agencia') === 'externa')> <strong>Agência externa</strong> que ainda não usa o sistema</label>
            <div class="dica">Outra empresa. Ela passa a ser independente e pode atender outras indústrias.</div>
            @error('tipo_agencia')<div class="erro">{{ $message }}</div>@enderror
        </div>
        <div class="linha">
            <div class="campo">
                <label for="nome">Nome da agência</label>
                <input id="nome" name="nome" value="{{ old('nome') }}" maxlength="120" required>
                @error('nome')<div class="erro">{{ $message }}</div>@enderror
            </div>
            <div class="campo">
                <label for="cnpj">CNPJ da agência</label>
                <input id="cnpj" name="cnpj" value="{{ old('cnpj') }}" maxlength="18" required placeholder="00.000.000/0000-00">
                @error('cnpj')<div class="erro">{{ $message }}</div>@enderror
            </div>
        </div>
            <div class="campo">
                <label for="subdominio">Nome do endereço</label>
                <div style="display:flex;align-items:center;gap:6px">
                    <input id="subdominio" name="subdominio" value="{{ old('subdominio') }}" maxlength="30" required pattern="[a-z][a-z0-9-]{1,28}[a-z0-9]" placeholder="minhaempresa" autocomplete="off" style="max-width:220px">
                    <span>.{{ config('trade.dominio_clientes') }}</span>
                </div>
                <div class="dica">É o endereço da empresa no sistema e também o código que o promotor digita no aplicativo. De 3 a 30 letras minúsculas, números ou hífen; não dá para mudar depois.</div>
                @error('subdominio')<div class="erro">{{ $message }}</div>@enderror
            </div>
        <div class="linha">
            <div class="campo">
                <label for="admin_nome">Responsável pela agência</label>
                <input id="admin_nome" name="admin_nome" value="{{ old('admin_nome') }}" maxlength="120" required>
                @error('admin_nome')<div class="erro">{{ $message }}</div>@enderror
            </div>
            <div class="campo">
                <label for="admin_email">E-mail do responsável</label>
                <input id="admin_email" type="email" name="admin_email" value="{{ old('admin_email') }}" maxlength="190" required>
                @error('admin_email')<div class="erro">{{ $message }}</div>@enderror
            </div>
        </div>
        <p class="dica">Depois de criar, aparece um link de primeiro acesso para o responsável criar a senha. Ele vale por 7 dias e só pode ser usado uma vez.</p>
        <button type="submit" class="botao">Criar agência</button>
    </form>
</x-layout>
