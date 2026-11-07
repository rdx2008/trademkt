<x-layout titulo="Marca do cliente" marca="Painel central">
    <x-slot:nav>@include('central.nav')</x-slot:nav>
    <div class="cabecalho">
        <div>
            <h1>Marca de {{ $cliente->nome }}</h1>
            <p class="rotulo">Controla o que o cliente pode personalizar e o que aparece no rodapé das telas dele.</p>
        </div>
        <a class="botao secundario" href="{{ route('central.clientes.index') }}">Voltar</a>
    </div>
    <form method="POST" action="{{ route('central.clientes.marca.salvar', $cliente) }}" class="cartao">
        @csrf
        @method('PUT')
        <div class="campo">
            <label class="check"><input type="checkbox" name="white_label" value="1" @checked($cliente->whiteLabelAtivo())> Liberar marca própria (nome, cor e logos) para o admin do cliente</label>
            <div class="dica">Desmarcado, o cliente volta ao visual padrão e perde a tela Configurações → Aparência. Os arquivos e as escolhas dele ficam guardados.</div>
        </div>
        <div class="campo">
            <label class="check"><input type="checkbox" name="mostrar_creditos" value="1" @checked($cliente->mostrarCreditos())> Mostrar "Desenvolvido por Trade Promo" no rodapé e no menu</label>
            <div class="dica">Desligado por padrão: o cliente vê só a própria marca.</div>
        </div>
        <button class="botao" type="submit">Salvar</button>
    </form>
</x-layout>
