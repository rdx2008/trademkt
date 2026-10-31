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

        @include('central.clientes.endereco-campos')
        <fieldset id="parceria-inicial" class="cartao" style="margin-bottom:20px"><legend>Parceria inicial da indústria (opcional)</legend><p class="dica">Para instalação de indústria: escolha uma agência agora ou deixe para buscar depois no painel. A agência ainda precisará aceitar.</p><div class="campo"><label for="agencia_rede_id">Agência aprovada</label><select name="agencia_rede_id" id="agencia_rede_id"><option value="">Escolher depois</option>@foreach ($agenciasRede as $agencia)<option value="{{ $agencia->id }}" @selected(old('agencia_rede_id') == $agencia->id)>{{ $agencia->tenant->nome }}</option>@endforeach</select></div><div class="campo"><label for="regiao_parceria">Região desejada</label><input type="text" name="regiao_parceria" id="regiao_parceria" value="{{ old('regiao_parceria') }}" maxlength="200"></div><div class="campo"><label for="mensagem_parceria">Mensagem para a agência</label><textarea name="mensagem_parceria" id="mensagem_parceria" rows="2" maxlength="2000">{{ old('mensagem_parceria') }}</textarea></div></fieldset>

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
    @push('scripts')
    <script>
    (() => {
        const tipo = document.getElementById('tipo');
        const secao = document.getElementById('parceria-inicial');
        const agencia = document.getElementById('agencia_rede_id');
        const regiao = document.getElementById('regiao_parceria');
        function atualizarParceria() {
            const ativa = tipo.value === 'industria';
            secao.hidden = !ativa;
            secao.querySelectorAll('input,select,textarea').forEach(campo => campo.disabled = !ativa);
            regiao.required = ativa && !!agencia.value;
        }
        tipo.addEventListener('change', atualizarParceria);
        agencia.addEventListener('change', atualizarParceria);
        atualizarParceria();
    })();
    </script>
    @endpush
</x-layout>
