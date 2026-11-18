<x-layout titulo="Importar produtos" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    <h1>Importar produtos</h1>

    <form method="POST" action="{{ route('skus.importar.enviar') }}" enctype="multipart/form-data" class="cartao">
        @csrf

        @if ($industrias->count() > 1)
            <div class="campo">
                <label for="industria_id">Indústria</label>
                <select id="industria_id" name="industria_id" required>
                    <option value="">Escolha...</option>
                    @foreach ($industrias as $id => $nome)
                        <option value="{{ $id }}" @selected((string) old('industria_id') === (string) $id)>{{ $nome }}</option>
                    @endforeach
                </select>
            </div>
        @elseif ($industrias->count() === 1)
            <p>Indústria: <strong>{{ $industrias->first() }}</strong></p>
        @endif

        <div class="campo">
            <label for="arquivo">Planilha (CSV ou XLSX) ou catálogo em PDF</label>
            <input type="file" id="arquivo" name="arquivo" accept=".csv,.xlsx,.txt,.pdf" required>
            <div class="dica">Nada é gravado agora: na próxima tela você revisa cada produto e escolhe o que gravar.</div>
        </div>

        <button type="submit" class="botao">Enviar e revisar</button>
        <a href="{{ route('skus.index') }}" class="botao secundario">Cancelar</a>
    </form>

    <div class="cartao">
        <h2>Planilha</h2>
        <p>
            A primeira linha deve ter os títulos das colunas, em qualquer ordem.
            <a href="{{ route('skus.importar.modelo') }}">Baixar planilha de exemplo</a>.
        </p>
        <ul>
            <li><strong>Obrigatórias:</strong> Código e Descrição.</li>
            <li><strong>Opcionais:</strong> Categoria; GTIN unidade, GTIN reembalagem, Qtd reembalagem, GTIN caixa master,
                Qtd caixa master. Também vale uma linha por embalagem, repetindo o código, com as colunas Tipo, Quantidade e GTIN.</li>
            <li>Código já cadastrado na indústria atualiza o produto; célula vazia mantém o valor atual.</li>
            <li>GTIN com dígito verificador errado é aceito e fica marcado para revisão no catálogo.</li>
        </ul>
    </div>

    <div class="cartao">
        <h2>Catálogo em PDF</h2>
        <p>
            O PDF precisa ter texto selecionável (não pode ser foto escaneada). A leitura é automática, por regras,
            e foi ajustada ao catálogo da Gaboardi. Catálogos com outro desenho podem trazer produtos faltando ou
            misturados: por isso tudo passa pela revisão, e os casos duvidosos aparecem com um aviso.
        </p>
        <ul>
            <li>Quantidade das embalagens: quando o texto do PDF não deixa claro, ela entra como 1 e vem com aviso.</li>
            <li>Se o PDF não for reconhecido, use a planilha.</li>
        </ul>
    </div>
</x-layout>
