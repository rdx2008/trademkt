<x-layout titulo="Editar vínculo" marca="Painel central">
    <x-slot:nav>@include('central.nav')</x-slot:nav>
    <h1>{{ $vinculo->agencia->nome }} atende {{ $vinculo->industria->nome }}</h1>
    @if ($errors->any())<div class="aviso erro"><ul class="mb-0">@foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ route('central.vinculos.update', $vinculo) }}" class="cartao">
        @csrf
        @method('PUT')
        @include('components.regioes-parceria', ['prefixo' => 'vinculo', 'campoRegioes' => 'regioes', 'regioesAtuais' => old('regioes', $vinculo->regioes ?? []), 'obrigatorio' => false])
        <div class="campo"><label><input type="checkbox" name="ativo" value="1" @checked(old('ativo', $vinculo->ativo))> Vínculo ativo</label><div class="dica">Desativado, a agência perde o acesso à operação desta indústria. Contratos, roteiros e visitas ficam guardados.</div></div>
        <button type="submit" class="botao">Salvar</button>
        <a href="{{ route('central.vinculos.index') }}" class="botao secundario">Cancelar</a>
    </form>
</x-layout>
