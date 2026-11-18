<fieldset class="cartao" style="margin-bottom:20px">
    <legend>Endereço de acesso</legend>
    <div class="acoes" style="margin-bottom:16px">
        <label class="check"><input type="radio" name="tipo_endereco" value="subdominio" @checked(old('tipo_endereco', 'subdominio') === 'subdominio')> Subdomínio do sistema</label>
        <label class="check"><input type="radio" name="tipo_endereco" value="proprio" @checked(old('tipo_endereco') === 'proprio')> Domínio próprio</label>
    </div>
    <div class="campo" data-endereco="subdominio">
        <label for="subdominio">Subdomínio desejado</label>
        <input id="subdominio" type="text" name="subdominio" value="{{ old('subdominio') }}" placeholder="gaboardi" pattern="[a-z0-9][a-z0-9\-]*" maxlength="63">
        <div class="dica">Endereço: <strong>seu-nome.{{ config('trade.dominio_clientes') }}</strong>. Use letras minúsculas, números ou hífen.</div>
    </div>
    <div class="campo" data-endereco="proprio">
        <label for="dominios">Domínio próprio</label>
        <textarea id="dominios" name="dominios" rows="2" placeholder="trade.suaempresa.com.br">{{ old('dominios') }}</textarea>
        <div class="dica">Somente o domínio, sem https://. Aceita um por linha ou separados por vírgula.</div>
    </div>
    <p class="dica">O endereço pode ser alterado depois. DNS e HTTPS precisam ser configurados no servidor para ativá-lo.</p>
</fieldset>
@push('scripts')
<script>
(() => {
    function atualizar() {
        const escolhido = document.querySelector('input[name="tipo_endereco"]:checked')?.value;
        document.querySelectorAll('[data-endereco]').forEach(grupo => {
            const ativo = grupo.dataset.endereco === escolhido;
            grupo.hidden = !ativo;
            grupo.querySelectorAll('input,textarea').forEach(campo => campo.required = ativo);
        });
    }
    document.querySelectorAll('input[name="tipo_endereco"]').forEach(campo => campo.addEventListener('change', atualizar));
    atualizar();
})();
</script>
@endpush
