@php($editando = $modelo->exists)
<x-layout :titulo="$editando ? $modelo->nome : 'Novo modelo de coleta'" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>
    <div class="cabecalho">
        <h1>{{ $editando ? 'Modelo: '.$modelo->nome : 'Novo modelo de coleta' }}</h1>
        <a class="botao secundario" href="{{ route('coletas.modelos.index') }}">Voltar</a>
    </div>

    <form method="POST" action="{{ $editando ? route('coletas.modelos.update', $modelo->id) : route('coletas.modelos.store') }}" class="cartao" id="form-modelo">
        @csrf
        @if ($editando) @method('PUT') @endif

        <div class="linha">
            <div class="campo">
                <label for="nome">Nome do modelo</label>
                <input id="nome" name="nome" value="{{ old('nome', $modelo->nome) }}" maxlength="120" required>
                @error('nome')<div class="erro">{{ $message }}</div>@enderror
            </div>
            <div class="campo">
                <label for="tipo">Tipo</label>
                <select id="tipo" name="tipo">
                    @foreach ($tiposModelo as $v => $r)<option value="{{ $v }}" @selected(old('tipo', $modelo->tipo) === $v)>{{ $r }}</option>@endforeach
                </select>
            </div>
            @if ($industrias->count() > 1 && ! $editando)
                <div class="campo">
                    <label for="industria_id">Indústria</label>
                    <select id="industria_id" name="industria_id">@foreach ($industrias as $id => $nome)<option value="{{ $id }}" @selected((int) old('industria_id') === $id)>{{ $nome }}</option>@endforeach</select>
                </div>
            @else
                <input type="hidden" name="industria_id" value="{{ $editando ? $modelo->industria_id : $industrias->keys()->first() }}">
            @endif
        </div>

        <div class="campo">
            <label>Redes de supermercados (opcional)</label>
            <div class="dica">Sem nenhuma marcada, o modelo vale para todas as lojas da indústria.</div>
            @foreach ($redes as $id => $nome)
                <label class="check"><input type="checkbox" name="redes[]" value="{{ $id }}" @checked(in_array($id, old('redes', $modelo->redes ?? [])))> {{ $nome }}</label>
            @endforeach
        </div>

        <h2>Campos</h2>
        <p class="dica">Um campo "por produto" é respondido uma vez para cada SKU da indústria. Alterar os campos de um modelo em uso cria uma nova versão; as respostas antigas continuam como foram dadas.</p>
        @error('campos')<div class="erro">{{ $message }}</div>@enderror
        <div id="campos">
            @foreach (old('campos', $modelo->campos ?? []) as $i => $c)
                <div class="cartao campo-modelo" style="margin-bottom:10px">
                    <div class="linha">
                        <div class="campo"><label>Pergunta</label><input name="campos[{{ $i }}][rotulo]" value="{{ $c['rotulo'] ?? '' }}" maxlength="120"></div>
                        <div class="campo"><label>Tipo de resposta</label>
                            <select name="campos[{{ $i }}][tipo]" class="tipo-campo">@foreach ($tiposCampo as $v => $r)<option value="{{ $v }}" @selected(($c['tipo'] ?? '') === $v)>{{ $r }}</option>@endforeach</select>
                        </div>
                    </div>
                    <div class="campo opcoes" @if (($c['tipo'] ?? '') !== 'escolha') style="display:none" @endif>
                        <label>Opções (uma por linha)</label>
                        <textarea name="campos[{{ $i }}][opcoes]" rows="3">{{ is_array($c['opcoes'] ?? null) ? implode("\n", $c['opcoes']) : ($c['opcoes'] ?? '') }}</textarea>
                    </div>
                    <div class="campo"><label>Papel nos relatórios (opcional)</label>
                        <select name="campos[{{ $i }}][papel]"><option value="">Nenhum</option>@foreach ($papeis as $v => $r)<option value="{{ $v }}" @selected(($c['papel'] ?? '') === $v)>{{ $r }}</option>@endforeach</select>
                    </div>
                    <label class="check"><input type="checkbox" name="campos[{{ $i }}][obrigatorio]" value="1" @checked(! empty($c['obrigatorio']))> Obrigatório</label>
                    <label class="check"><input type="checkbox" name="campos[{{ $i }}][por_sku]" value="1" @checked(! empty($c['por_sku']))> Por produto (uma resposta para cada SKU)</label>
                    <button type="button" class="link-botao remover-campo">Remover campo</button>
                </div>
            @endforeach
        </div>
        <button type="button" class="botao secundario" id="novo-campo">+ Adicionar campo</button>

        <div class="campo" style="margin-top:16px">
            <label for="alerta_dias">Alertar quando um produto vencer em até (dias)</label>
            <input id="alerta_dias" type="number" name="alerta_dias" min="1" max="365" value="{{ old('alerta_dias', $modelo->alerta_dias) }}" placeholder="Sem alerta">
            <div class="dica">Vale para modelos com um campo de papel "Data de validade". O alerta vai para a gestão da indústria, o representante da loja e a agência.</div>
        </div>
        <div class="campo" style="margin-top:16px">
            <label class="check"><input type="checkbox" name="ativo" value="1" @checked(old('ativo', $modelo->ativo))> Ativo (o promotor recebe este modelo nas visitas)</label>
        </div>
        <button type="submit" class="botao">Salvar modelo</button>
    </form>

    @push('scripts')
        <script>
            (function () {
                var cont = document.getElementById('campos');
                var tipos = @json($tiposCampo);
                var papeis = @json($papeis);
                function opcoesPapel() { return '<option value="">Nenhum</option>' + Object.keys(papeis).map(function (k) { return '<option value="' + k + '">' + papeis[k] + '</option>'; }).join(''); }
                function opcoesTipo() { return Object.keys(tipos).map(function (k) { return '<option value="' + k + '">' + tipos[k] + '</option>'; }).join(''); }
                var proximo = cont.querySelectorAll('.campo-modelo').length;
                function ligar(bloco) {
                    var sel = bloco.querySelector('.tipo-campo');
                    sel.addEventListener('change', function () { bloco.querySelector('.opcoes').style.display = sel.value === 'escolha' ? '' : 'none'; });
                    bloco.querySelector('.remover-campo').addEventListener('click', function () { bloco.remove(); });
                }
                cont.querySelectorAll('.campo-modelo').forEach(ligar);
                document.getElementById('novo-campo').addEventListener('click', function () {
                    var i = proximo++;
                    var d = document.createElement('div');
                    d.className = 'cartao campo-modelo'; d.style.marginBottom = '10px';
                    d.innerHTML = '<div class="linha"><div class="campo"><label>Pergunta</label><input name="campos[' + i + '][rotulo]" maxlength="120"></div>' +
                        '<div class="campo"><label>Tipo de resposta</label><select name="campos[' + i + '][tipo]" class="tipo-campo">' + opcoesTipo() + '</select></div></div>' +
                        '<div class="campo opcoes" style="display:none"><label>Opções (uma por linha)</label><textarea name="campos[' + i + '][opcoes]" rows="3"></textarea></div>' +
                        '<div class="campo"><label>Papel nos relatórios (opcional)</label><select name="campos[' + i + '][papel]">' + opcoesPapel() + '</select></div>' +
                        '<label class="check"><input type="checkbox" name="campos[' + i + '][obrigatorio]" value="1"> Obrigatório</label> ' +
                        '<label class="check"><input type="checkbox" name="campos[' + i + '][por_sku]" value="1"> Por produto (uma resposta para cada SKU)</label> ' +
                        '<button type="button" class="link-botao remover-campo">Remover campo</button>';
                    cont.appendChild(d); ligar(d);
                });
            })();
        </script>
    @endpush
</x-layout>
