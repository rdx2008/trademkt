@php
    $campoRegioes = $campoRegioes ?? 'regioes';
    $listaRegioes = array_values($regioesAtuais ?: [['uf' => '', 'cidade' => '']]);
@endphp
<div data-editor-regioes data-proximo="{{ count($listaRegioes) }}" data-obrigatorio="{{ ($obrigatorio ?? true) ? '1' : '0' }}" data-habilitado="1">
    <p class="dica">Regiões que a agência atende nesta indústria (informativo). Sem nenhuma linha, vale para todas as regiões.</p>
    <div data-lista-regioes>
        @foreach ($listaRegioes as $indice => $regiao)
            <div class="cartao" data-linha-regiao style="margin:12px 0;padding:16px">
                <div class="cabecalho"><strong>Região <span data-numero-regiao>{{ $indice + 1 }}</span></strong><button class="link-botao" data-remover-regiao type="button" @disabled(count($listaRegioes) === 1)>Remover região</button></div>
                @include('components.estado-municipio', ['prefixo' => $prefixo.'-'.$indice, 'campoUf' => $campoRegioes.'['.$indice.'][uf]', 'campoCidade' => $campoRegioes.'['.$indice.'][cidade]', 'ufAtual' => $regiao['uf'] ?? '', 'cidadeAtual' => $regiao['cidade'] ?? '', 'obrigatorio' => $obrigatorio ?? true])
            </div>
        @endforeach
    </div>
    <template data-template-regiao>
        <div class="cartao" data-linha-regiao style="margin:12px 0;padding:16px">
            <div class="cabecalho"><strong>Região <span data-numero-regiao></span></strong><button class="link-botao" data-remover-regiao type="button">Remover região</button></div>
            @include('components.estado-municipio', ['prefixo' => $prefixo.'-__INDEX__', 'campoUf' => $campoRegioes.'[__INDEX__][uf]', 'campoCidade' => $campoRegioes.'[__INDEX__][cidade]', 'ufAtual' => '', 'cidadeAtual' => '', 'obrigatorio' => $obrigatorio ?? true])
        </div>
    </template>
    <div class="acoes"><button class="botao secundario" type="button" data-adicionar-regiao>+ Adicionar região</button><span class="rotulo" data-contagem-regioes aria-live="polite">{{ count($listaRegioes) }} de 30 regiões</span></div>
</div>
@once
@push('scripts')
<script>
document.querySelectorAll('[data-editor-regioes]').forEach(editor => {
    const lista = editor.querySelector('[data-lista-regioes]');
    const adicionar = editor.querySelector('[data-adicionar-regiao]');
    function atualizar() {
        const linhas = [...lista.querySelectorAll('[data-linha-regiao]')];
        const habilitado = editor.dataset.habilitado === '1';
        linhas.forEach((linha, indice) => {
            linha.querySelector('[data-numero-regiao]').textContent = indice + 1;
            const remover = linha.querySelector('[data-remover-regiao]');
            remover.disabled = !habilitado || linhas.length <= 1;
            remover.setAttribute('aria-label', 'Remover região ' + (indice + 1));
            linha.querySelectorAll('input,select').forEach(campo => campo.disabled = !habilitado);
            linha.querySelector('select').required = habilitado && editor.dataset.obrigatorio === '1';
        });
        adicionar.disabled = !habilitado || linhas.length >= 30;
        editor.querySelector('[data-contagem-regioes]').textContent = linhas.length + ' de 30 regiões';
    }
    adicionar.addEventListener('click', () => {
        if (lista.children.length >= 30) return;
        const indice = Number(editor.dataset.proximo);
        editor.dataset.proximo = indice + 1;
        lista.insertAdjacentHTML('beforeend', editor.querySelector('template').innerHTML.replaceAll('__INDEX__', String(indice)));
        atualizar();
    });
    lista.addEventListener('click', evento => {
        const botao = evento.target.closest('[data-remover-regiao]');
        if (botao && lista.children.length > 1) { botao.closest('[data-linha-regiao]').remove(); atualizar(); }
    });
    editor.addEventListener('regioes:atualizar', atualizar);
    atualizar();
});
</script>
@endpush
@endonce
