@php
    $campoUf = $campoUf ?? 'uf';
    $campoCidade = $campoCidade ?? 'cidade';
    $idUf = $idUf ?? $prefixo.'-uf';
    $idCidade = $idCidade ?? $prefixo.'-cidade';
@endphp
<div class="linha estado-municipio">
    <div class="campo">
        <label for="{{ $idUf }}">Estado desejado</label>
        <select id="{{ $idUf }}" name="{{ $campoUf }}" @required($obrigatorio ?? true)>
            <option value="">Selecione um estado</option>
            @foreach (\App\Services\Rede\PerfilAgencia::ESTADOS as $sigla => $nome)
                <option value="{{ $sigla }}" @selected(($ufAtual ?? '') === $sigla)>{{ $nome }} ({{ $sigla }})</option>
            @endforeach
        </select>
    </div>
    <div class="campo">
        <label for="{{ $idCidade }}">Município (opcional)</label>
        <input id="{{ $idCidade }}" name="{{ $campoCidade }}" type="text" maxlength="100" value="{{ $cidadeAtual ?? '' }}" placeholder="Ex.: Caxias do Sul">
        <p class="dica">Deixe em branco para informar apenas o estado de interesse. O atendimento é combinado com a agência.</p>
    </div>
</div>
