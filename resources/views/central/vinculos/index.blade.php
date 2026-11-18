<x-layout titulo="Vínculos" marca="Painel central">
    <x-slot:nav>@include('central.nav')</x-slot:nav>
    <div class="painel-cabecalho"><div><div class="sobretitulo">PLATAFORMA / OPERAÇÃO</div><h1>Vínculos entre indústrias e agências</h1><p class="texto-apoio">A agência vinculada opera dentro da instalação da indústria com a própria equipe de promotores. Só o painel central cria e desfaz vínculos.</p></div></div>
    @if (session('status'))<div class="aviso ok">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="aviso erro"><ul class="mb-0">@foreach ($errors->all() as $erro)<li>{{ $erro }}</li>@endforeach</ul></div>@endif
    <section class="cartao tabela-rolagem"><table><thead><tr><th>Indústria</th><th>Agência</th><th>Regiões</th><th>Situação</th><th></th></tr></thead><tbody>
        @forelse ($vinculos as $vinculo)
            <tr>
                <td>{{ $vinculo->industria->nome }}<div class="rotulo">{{ $vinculo->industria_tenant_id }}</div></td>
                <td>{{ $vinculo->agencia->nome }}<div class="rotulo">{{ $vinculo->agencia_tenant_id }}</div></td>
                <td>{{ $vinculo->regioesRotulo() }}</td>
                <td><span @class(['selo', 'ok' => $vinculo->ativo, 'off' => ! $vinculo->ativo])>{{ $vinculo->ativo ? 'Ativo' : 'Desativado' }}</span></td>
                <td><a href="{{ route('central.vinculos.edit', $vinculo) }}">Editar</a></td>
            </tr>
        @empty
            <tr><td colspan="5">Nenhum vínculo ainda. Crie as instalações em Clientes e vincule abaixo.</td></tr>
        @endforelse
    </tbody></table></section>
    <section class="cartao secao-painel">
        <h2>Vincular agência a uma indústria</h2>
        <form method="POST" action="{{ route('central.vinculos.store') }}">
            @csrf
            <div class="linha">
                <div class="campo"><label for="industria_tenant_id">Indústria</label><select id="industria_tenant_id" name="industria_tenant_id" required><option value="">Selecione</option>@foreach ($industrias as $t)<option value="{{ $t->id }}" @selected(old('industria_tenant_id') === $t->id)>{{ $t->nome }}</option>@endforeach</select></div>
                <div class="campo"><label for="agencia_tenant_id">Agência</label><select id="agencia_tenant_id" name="agencia_tenant_id" required><option value="">Selecione</option>@foreach ($agencias as $t)<option value="{{ $t->id }}" @selected(old('agencia_tenant_id') === $t->id)>{{ $t->nome }}</option>@endforeach</select></div>
            </div>
            @include('components.regioes-parceria', ['prefixo' => 'vinculo', 'campoRegioes' => 'regioes', 'regioesAtuais' => old('regioes', []), 'obrigatorio' => false])
            <button class="botao">Vincular</button>
        </form>
    </section>
</x-layout>
