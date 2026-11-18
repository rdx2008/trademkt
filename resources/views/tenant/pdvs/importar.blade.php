<x-layout titulo="Importar PDVs" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    <h1>Importar PDVs por planilha</h1>

    <form method="POST" action="{{ route('pdvs.importar.enviar') }}" enctype="multipart/form-data" class="cartao">
        @csrf

        <div class="campo">
            <label for="arquivo">Planilha (CSV ou XLSX)</label>
            <input type="file" id="arquivo" name="arquivo" accept=".csv,.xlsx,.txt" required>
            <div class="dica">Nada é gravado agora: na próxima tela você revisa cada linha e escolhe o que gravar.</div>
        </div>

        <button type="submit" class="botao">Enviar e revisar</button>
        <a href="{{ route('pdvs.index') }}" class="botao secundario">Cancelar</a>
    </form>

    <div class="cartao">
        <h2>Formato</h2>
        <p>A primeira linha deve ter os títulos das colunas. A ordem não importa; colunas desconhecidas são ignoradas.</p>
        <ul>
            <li><strong>Obrigatórias:</strong> CNPJ, Nome (ou Loja), Cidade, UF.</li>
            <li><strong>Opcionais:</strong> Rede, Canal, Região, CEP, Logradouro (ou Endereço), Número, Complemento, Bairro,
                Latitude, Longitude, Raio (150 a 300 m; padrão 200).</li>
            <li>Canal: {{ implode(', ', \App\Enums\CanalPdv::opcoes()) }}.</li>
            <li>Rede e região que ainda não existem são criadas na gravação.</li>
            <li>CNPJ já cadastrado atualiza o PDV; célula vazia mantém o valor atual.</li>
            <li>Sem latitude e longitude, o endereço é localizado depois, automaticamente.</li>
        </ul>
    </div>
</x-layout>
