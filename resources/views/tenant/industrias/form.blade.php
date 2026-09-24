@php($editando = $industria->exists)
<x-layout :titulo="$editando ? 'Editar indústria' : 'Nova indústria'" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    <h1>{{ $editando ? 'Editar indústria' : 'Nova indústria' }}</h1>

    <form method="POST" action="{{ $editando ? route('industrias.update', $industria) : route('industrias.store') }}" class="cartao">
        @csrf
        @if ($editando) @method('PUT') @endif

        <div class="linha">
            <div class="campo">
                <label for="nome">Nome</label>
                <input type="text" id="nome" name="nome" value="{{ old('nome', $industria->nome) }}" required>
            </div>
            <div class="campo">
                <label for="cnpj">CNPJ</label>
                <input type="text" id="cnpj" name="cnpj" value="{{ old('cnpj', $industria->cnpj) }}">
            </div>
            <div class="campo">
                <label for="segmento">Segmento</label>
                <input type="text" id="segmento" name="segmento" value="{{ old('segmento', $industria->segmento) }}" placeholder="Ex.: bazar/limpeza">
            </div>
        </div>

        <div class="campo">
            <input type="hidden" name="ativo" value="0">
            <label class="check"><input type="checkbox" name="ativo" value="1" @checked(old('ativo', $industria->ativo))> Ativa</label>
        </div>

        <button type="submit" class="botao">Salvar</button>
        <a href="{{ route('industrias.index') }}" class="botao secundario">Cancelar</a>
    </form>
</x-layout>
