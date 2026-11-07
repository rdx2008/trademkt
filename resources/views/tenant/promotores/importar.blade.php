<x-layout titulo="Importar promotores" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    <h1>Importar promotores por planilha</h1>

    <form method="POST" action="{{ route('promotores.importar.enviar') }}" enctype="multipart/form-data" class="cartao">
        @csrf
        <div class="campo">
            <label for="arquivo">Planilha (CSV ou XLSX)</label>
            <input type="file" id="arquivo" name="arquivo" accept=".csv,.xlsx,.txt" required>
            <div class="dica">Cada linha válida vira um promotor com um link de convite. Linhas com erro não são gravadas e aparecem no relatório.</div>
        </div>
        <button type="submit" class="botao">Importar</button>
        <a href="{{ route('promotores.index') }}" class="botao secundario">Cancelar</a>
        <a href="{{ route('promotores.importar.modelo') }}" class="botao secundario">Baixar modelo</a>
    </form>

    <div class="cartao">
        <h2>Formato</h2>
        <p>A primeira linha tem os títulos das colunas. A ordem não importa; colunas desconhecidas são ignoradas.</p>
        <ul>
            <li><strong>Obrigatórias:</strong> Nome, E-mail e CPF.</li>
            <li><strong>Opcionais:</strong> Telefone, Vínculo (CLT ou Freelancer; vazio = CLT), Cidade base, UF e Observações.</li>
            <li>E-mail ou CPF já cadastrados não são alterados: a linha aparece como erro.</li>
            <li>Até {{ \App\Services\Promotores\ImportacaoPromotores::MAXIMO_LINHAS }} linhas por arquivo.</li>
            <li>Os links de convite aparecem só no relatório logo após a importação. Baixe a planilha de links antes de sair da tela.</li>
        </ul>
    </div>
</x-layout>
