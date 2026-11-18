/*
 * Atalhos do painel inicial personalizáveis: cada pessoa reordena e oculta os seus, e a escolha fica
 * guardada neste navegador (localStorage), por usuário. Nada vai para o servidor.
 */
(function () {
    var caixa = document.querySelector('.painel-atalhos[data-personalizavel]');
    if (!caixa) { return; }
    var chave = 'trade-atalhos:' + (caixa.getAttribute('data-chave') || 'padrao');
    var itens = [].slice.call(caixa.querySelectorAll('.atalho[data-id]'));
    if (itens.length === 0) { return; }

    var estado = { ordem: [], ocultos: [] };
    try {
        var salvo = JSON.parse(window.localStorage.getItem(chave) || 'null');
        if (salvo && Array.isArray(salvo.ordem) && Array.isArray(salvo.ocultos)) { estado = salvo; }
    } catch (e) { /* sem armazenamento: usa o padrão */ }

    var editando = false;
    var barra = document.createElement('div');
    barra.className = 'atalhos-barra';
    barra.style.cssText = 'display:flex;justify-content:flex-end;gap:8px;margin:0 0 8px';
    var botao = document.createElement('button');
    botao.type = 'button';
    botao.className = 'link-botao';
    barra.appendChild(botao);
    caixa.parentNode.insertBefore(barra, caixa);

    function salvar() {
        try { window.localStorage.setItem(chave, JSON.stringify(estado)); } catch (e) { /* ignora */ }
    }

    function idsNaOrdem() {
        var todos = itens.map(function (i) { return i.getAttribute('data-id'); });
        var ordem = estado.ordem.filter(function (id) { return todos.indexOf(id) !== -1; });
        todos.forEach(function (id) { if (ordem.indexOf(id) === -1) { ordem.push(id); } });
        return ordem;
    }

    function desenhar() {
        var ordem = idsNaOrdem();
        var porId = {};
        itens.forEach(function (i) { porId[i.getAttribute('data-id')] = i; });
        ordem.forEach(function (id, pos) {
            var item = porId[id];
            var oculto = estado.ocultos.indexOf(id) !== -1;
            item.style.display = oculto && !editando ? 'none' : '';
            item.style.opacity = oculto && editando ? '0.45' : '';
            caixa.appendChild(item);
            var ctl = item.querySelector('.atalho-controles');
            if (ctl) { ctl.remove(); }
            if (editando) {
                ctl = document.createElement('span');
                ctl.className = 'atalho-controles';
                ctl.style.cssText = 'display:flex;gap:6px;margin-top:6px';
                [['Subir', function () { mover(id, -1); }, pos === 0],
                 ['Descer', function () { mover(id, 1); }, pos === ordem.length - 1],
                 [oculto ? 'Mostrar' : 'Ocultar', function () { alternar(id); }, false]].forEach(function (d) {
                    var b = document.createElement('button');
                    b.type = 'button'; b.textContent = d[0]; b.disabled = d[2]; b.className = 'link-botao';
                    b.addEventListener('click', function (ev) { ev.preventDefault(); ev.stopPropagation(); d[1](); });
                    ctl.appendChild(b);
                });
                item.appendChild(ctl);
            }
        });
        botao.textContent = editando ? 'Concluir' : 'Personalizar atalhos';
    }

    function mover(id, delta) {
        var ordem = idsNaOrdem();
        var i = ordem.indexOf(id);
        var j = i + delta;
        if (i < 0 || j < 0 || j >= ordem.length) { return; }
        ordem.splice(j, 0, ordem.splice(i, 1)[0]);
        estado.ordem = ordem;
        salvar();
        desenhar();
    }

    function alternar(id) {
        var i = estado.ocultos.indexOf(id);
        if (i === -1) { estado.ocultos.push(id); } else { estado.ocultos.splice(i, 1); }
        salvar();
        desenhar();
    }

    // No modo de edição os atalhos não navegam, para não sair da tela sem querer.
    caixa.addEventListener('click', function (ev) {
        if (editando && ev.target.closest('.atalho')) { ev.preventDefault(); }
    });
    botao.addEventListener('click', function () { editando = !editando; desenhar(); });
    desenhar();
})();
