<x-layout titulo="Novo cliente" marca="Painel central">
    <x-slot:nav>@include('central.nav')</x-slot:nav>

    <h1>Novo cliente</h1>

    <form method="POST" action="{{ route('central.clientes.store') }}" class="cartao">
        @csrf

        <div class="linha">
            <div class="campo">
                <label for="nome">Nome da empresa</label>
                <input type="text" id="nome" name="nome" value="{{ old('nome') }}" required>
            </div>
            <div class="campo">
                <label for="tipo">Tipo de instalação</label>
                <select id="tipo" name="tipo" required>
                    @foreach ($tipos as $tipo)
                        <option value="{{ $tipo->value }}" @selected(old('tipo') === $tipo->value)>{{ $tipo->label() }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="linha">
            <div class="campo">
                <label for="id">Identificador</label>
                <input type="text" id="id" name="id" value="{{ old('id') }}" required pattern="[a-z][a-z0-9_]{2,39}">
                <div class="dica">Ex.: gaboardi. Vira o nome do banco (cli_gaboardi) e da pasta. Não muda depois.</div>
            </div>
            <div class="campo">
                <label for="codigo">Código no app</label>
                <input type="text" id="codigo" name="codigo" value="{{ old('codigo') }}" pattern="[a-z0-9_\-]{3,40}">
                <div class="dica">O promotor digita no primeiro acesso. Em branco, usa o identificador.</div>
            </div>
        </div>

        <div class="campo">
            <label for="dominios">Domínios</label>
            <textarea id="dominios" name="dominios" rows="2" required>{{ old('dominios') }}</textarea>
            <div class="dica">Um por linha ou separados por vírgula. Ex.: trade.gaboardi.com.br</div>
        </div>

        <div class="linha">
            <div class="campo">
                <label for="admin_nome">Nome do admin</label>
                <input type="text" id="admin_nome" name="admin_nome" value="{{ old('admin_nome') }}" required>
            </div>
            <div class="campo">
                <label for="admin_email">E-mail do admin</label>
                <input type="email" id="admin_email" name="admin_email" value="{{ old('admin_email') }}" required>
            </div>
            <div class="campo">
                <label for="admin_senha">Senha do admin</label>
                <input type="password" id="admin_senha" name="admin_senha" autocomplete="new-password">
                <div class="dica">Em branco, uma senha é gerada.</div>
            </div>
        </div>

        <button type="submit" class="botao">Criar cliente</button>
        <a href="{{ route('central.clientes.index') }}" class="botao secundario">Cancelar</a>
    </form>
</x-layout>
