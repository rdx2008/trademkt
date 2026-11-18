// Ajudas de formulário: máscara de CPF e telefone e botão "ver senha".
(function () {
    function soDigitos(v) { return (v || '').replace(/\D+/g, ''); }

    function cpf(v) {
        var d = soDigitos(v).slice(0, 11);
        if (d.length > 9) { return d.replace(/^(\d{3})(\d{3})(\d{3})(\d{0,2}).*/, '$1.$2.$3-$4'); }
        if (d.length > 6) { return d.replace(/^(\d{3})(\d{3})(\d{0,3}).*/, '$1.$2.$3'); }
        if (d.length > 3) { return d.replace(/^(\d{3})(\d{0,3}).*/, '$1.$2'); }
        return d;
    }

    // (54) 99999-0000 (celular, 11 dígitos) ou (54) 3333-4444 (fixo, 10 dígitos)
    function telefone(v) {
        var d = soDigitos(v);
        if (d.length > 11 && d.indexOf('55') === 0) { d = d.slice(2); } // +55 do código do país
        d = d.slice(0, 11);
        if (d.length === 0) { return ''; }
        if (d.length <= 2) { return '(' + d; }
        if (d.length <= 6) { return d.replace(/^(\d{2})(\d{0,4}).*/, '($1) $2'); }
        if (d.length <= 10) { return d.replace(/^(\d{2})(\d{4})(\d{0,4}).*/, '($1) $2-$3'); }
        return d.replace(/^(\d{2})(\d{5})(\d{0,4}).*/, '($1) $2-$3');
    }

    var mascaras = { cpf: cpf, telefone: telefone };

    document.querySelectorAll('input[data-mascara]').forEach(function (campo) {
        var f = mascaras[campo.dataset.mascara];
        if (!f) { return; }
        campo.value = f(campo.value);
        campo.addEventListener('input', function () { campo.value = f(campo.value); });
    });

    document.querySelectorAll('input[data-ver-senha]').forEach(function (campo) {
        var caixa = document.createElement('div');
        caixa.style.position = 'relative';
        campo.parentNode.insertBefore(caixa, campo);
        caixa.appendChild(campo);
        campo.style.paddingRight = '44px';
        var botao = document.createElement('button');
        botao.type = 'button';
        botao.setAttribute('aria-label', 'Mostrar senha');
        botao.setAttribute('aria-pressed', 'false');
        botao.style.cssText = 'position:absolute;right:6px;top:50%;transform:translateY(-50%);border:0;background:transparent;cursor:pointer;padding:6px;line-height:0;color:inherit;opacity:.7';
        var olho = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
        var cortado = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
        botao.innerHTML = olho;
        botao.addEventListener('click', function () {
            var mostrar = campo.type === 'password';
            campo.type = mostrar ? 'text' : 'password';
            botao.innerHTML = mostrar ? cortado : olho;
            botao.setAttribute('aria-label', mostrar ? 'Ocultar senha' : 'Mostrar senha');
            botao.setAttribute('aria-pressed', mostrar ? 'true' : 'false');
        });
        caixa.appendChild(botao);
    });
})();
