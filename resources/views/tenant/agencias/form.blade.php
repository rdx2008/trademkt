@php($editando = $agencia->exists)
<x-layout :titulo="$editando ? 'Editar agência' : 'Nova agência'" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    <h1>{{ $editando ? 'Editar agência' : 'Nova agência' }}</h1>

    <form method="POST" action="{{ $editando ? route('agencias.update', $agencia) : route('agencias.store') }}" class="cartao">
        @csrf
        @if ($editando) @method('PUT') @endif

        <div class="linha">
            <div class="campo">
                <label for="nome">Nome</label>
                <input type="text" id="nome" name="nome" value="{{ old('nome', $agencia->nome) }}" required>
            </div>
            <div class="campo">
                <label for="cnpj">CNPJ</label>
                <input type="text" id="cnpj" name="cnpj" value="{{ old('cnpj', $agencia->cnpj) }}">
            </div>
        </div>

        <div class="campo">
            <input type="hidden" name="ativo" value="0">
            <label class="check"><input type="checkbox" name="ativo" value="1" @checked(old('ativo', $agencia->ativo))> Ativa</label>
        </div>

        <button type="submit" class="botao">Salvar</button>
        <a href="{{ route('agencias.index') }}" class="botao secundario">Cancelar</a>
    </form>
</x-layout>
