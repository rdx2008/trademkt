@php($editando = $item->exists)
@php($titulo = $editando ? 'Editar '.mb_strtolower($rotulos['singular']) : $rotulos['novo'])
<x-layout :titulo="$titulo" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    <h1>{{ $titulo }}</h1>

    <form method="POST" action="{{ $editando ? route($rota.'.update', $item->id) : route($rota.'.store') }}" class="cartao">
        @csrf
        @if ($editando) @method('PUT') @endif

        <div class="campo">
            <label for="nome">Nome</label>
            <input type="text" id="nome" name="nome" value="{{ old('nome', $item->nome) }}" required maxlength="80">
        </div>

        <div class="campo">
            <input type="hidden" name="ativo" value="0">
            <label class="check"><input type="checkbox" name="ativo" value="1" @checked(old('ativo', $item->ativo))> Ativa</label>
        </div>

        <button type="submit" class="botao">Salvar</button>
        <a href="{{ route($rota.'.index') }}" class="botao secundario">Cancelar</a>
    </form>
</x-layout>
