<x-layout titulo="Marca do cliente" marca="Painel central">
    <x-slot:nav>@include('central.nav')</x-slot:nav>
    <div class="cabecalho">
        <div>
            <h1>Configurações de {{ $cliente->nome }}</h1>
            <p class="rotulo">Marca própria, créditos no rodapé e fuso horário da empresa.</p>
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
        <div class="campo">
            <label for="fuso">Fuso horário</label>
            <select id="fuso" name="fuso">
                @foreach (\App\Support\Fuso::OPCOES as $valor => $rotulo)
                    <option value="{{ $valor }}" @selected(\App\Support\Fuso::de($cliente) === $valor)>{{ $rotulo }}</option>
                @endforeach
            </select>
            <div class="dica">Define o "dia de hoje" das visitas, a geração do roteiro, o encerramento do dia e a hora das fotos desta empresa. Trocar não converte horários já gravados: escolha antes de começar a operar.</div>
        </div>
        <button class="botao" type="submit">Salvar</button>
    </form>
</x-layout>
