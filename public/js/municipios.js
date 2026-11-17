// Seletor de cidade por UF: ao escolher a UF, carrega os municípios (IBGE) no select de cidade.
(function () {
    function iniciar(uf) {
        var cidade = document.getElementById(uf.dataset.cidade);
        if (!cidade) { return; }
        var url = uf.dataset.url;
        function carregar(manter) {
            var sigla = uf.value;
            cidade.innerHTML = '';
            var vazio = document.createElement('option');
            vazio.value = '';
            vazio.textContent = sigla ? 'Carregando…' : 'Escolha a UF primeiro';
            cidade.appendChild(vazio);
            if (!sigla) { return; }
            fetch(url.replace('__UF__', sigla), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : []; })
                .then(function (lista) {
                    vazio.textContent = 'Escolha a cidade';
                    lista.forEach(function (m) {
                        var o = document.createElement('option');
                        o.value = m.codigo;
                        o.textContent = m.nome;
                        if (manter && manter === m.codigo) { o.selected = true; }
                        cidade.appendChild(o);
                    });
                })
                .catch(function () { vazio.textContent = 'Não foi possível carregar as cidades'; });
        }
        uf.addEventListener('change', function () { carregar(''); });
        carregar(cidade.dataset.atual || '');
    }
    document.querySelectorAll('select[data-cidade]').forEach(iniciar);
})();
