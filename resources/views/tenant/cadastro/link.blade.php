<x-layout titulo="Link de cadastro" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>
    <div class="cabecalho">
        <div>
            <h1>Link de cadastro dos promotores</h1>
            <p class="texto-apoio">Um link único da agência: quem recebe preenche o próprio cadastro, sem precisar do seu aceite. Depois é só marcar em que indústrias a pessoa atua.</p>
        </div>
        <div class="acoes"><a class="botao secundario" href="{{ route('promotores.index') }}">Voltar aos promotores</a></div>
    </div>

    <div class="cartao">
        <form method="POST" action="{{ route('promotores.link.alternar') }}">
            @csrf
            @method('PATCH')
            <p>Situação: <strong>{{ $ativo ? 'Ligado' : 'Desligado' }}</strong></p>
            <input type="hidden" name="ativo" value="{{ $ativo ? 0 : 1 }}">
            <button type="submit" class="botao {{ $ativo ? 'secundario' : '' }}">{{ $ativo ? 'Desligar o cadastro' : 'Ligar o cadastro' }}</button>
        </form>
        <div class="campo" style="margin-top:16px">
            <label for="link">Link para enviar ao promotor</label>
            <input type="text" id="link" value="{{ $link }}" readonly onclick="this.select()">
            <div class="dica">{{ $ativo ? 'Cadastro aberto: quem tiver o link consegue se cadastrar.' : 'Com o cadastro desligado, quem abrir o link vê que está indisponível.' }}</div>
        </div>
    </div>

    <div class="cartao" style="margin-top:16px">
        <h3>Termo de uso compartilhado da rede</h3>
        <p class="texto-apoio">É o texto que o promotor aceita no cadastro (versão <code>{{ $versao }}</code>). Deixe como está para usar o texto padrão ou ajuste. Cada mudança cria uma nova versão, e o aceite de cada pessoa guarda a versão que ela viu.</p>
        <form method="POST" action="{{ route('promotores.link.termo') }}">
            @csrf
            @method('PUT')
            <div class="campo">
                <textarea name="termo" rows="12" maxlength="6000">{{ old('termo', $termo) }}</textarea>
            </div>
            <button type="submit" class="botao">Salvar o termo</button>
        </form>
    </div>
</x-layout>
