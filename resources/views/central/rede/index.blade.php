<x-layout titulo="Rede de promotores" marca="Painel central">
    <x-slot:nav>@include('central.nav')</x-slot:nav>
    @push('head')<meta name="robots" content="noindex,nofollow">@endpush
    <div class="cabecalho">
        <div>
            <h1>Rede de promotores</h1>
            <p class="texto-apoio">Quantas pessoas únicas a plataforma já tem, por região. Só números: nenhum dado pessoal aparece aqui. A mesma pessoa em várias agências conta uma vez.</p>
        </div>
    </div>

    <div class="grade-indicadores" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px">
        <div class="cartao"><div class="rotulo">Promotores na plataforma</div><h2>{{ (int) $totais->total }}</h2></div>
        <div class="cartao"><div class="rotulo">Com cadastro ativo</div><h2>{{ (int) $totais->ativos }}</h2></div>
        <div class="cartao"><div class="rotulo">Com aceite do termo da rede</div><h2>{{ (int) $totais->com_aceite }}</h2></div>
        <div class="cartao"><div class="rotulo">Sem cidade escolhida</div><h2>{{ $semCidade }}</h2></div>
    </div>

    <h2>Por UF</h2>
    <div class="tabela-rolagem">
        <table>
            <thead><tr><th>UF</th><th>Cadastrados</th><th>Ativos</th><th>Com aceite</th><th></th></tr></thead>
            <tbody>
                @forelse ($porUf as $linha)
                    <tr>
                        <td>{{ $linha->uf !== '' ? $linha->uf : 'Sem UF' }}</td>
                        <td>{{ (int) $linha->total }}</td>
                        <td>{{ (int) $linha->ativos }}</td>
                        <td>{{ (int) $linha->com_aceite }}</td>
                        <td>@if ($linha->uf !== '')<a href="{{ route('central.rede.index', ['uf' => $linha->uf]) }}#cidades">Ver cidades</a>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5">Ainda não há promotores cadastrados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2 id="cidades" style="margin-top:24px">Por cidade{{ $uf ? ' · '.$uf : '' }}</h2>
    <form method="GET" class="filtros">
        <select name="uf" onchange="this.form.submit()">
            <option value="">Todas as UFs</option>
            @foreach ($ufs as $sigla)<option value="{{ $sigla }}" @selected($uf === $sigla)>{{ $sigla }}</option>@endforeach
        </select>
        <noscript><button type="submit" class="botao secundario">Filtrar</button></noscript>
    </form>
    <div class="tabela-rolagem">
        <table>
            <thead><tr><th>Cidade</th><th>UF</th><th>Cadastrados</th><th>Ativos</th><th>Com aceite</th></tr></thead>
            <tbody>
                @forelse ($porCidade as $linha)
                    <tr>
                        <td>{{ $linha->cidade !== '' ? $linha->cidade : 'Sem cidade escolhida' }}</td>
                        <td>{{ $linha->uf }}</td>
                        <td>{{ (int) $linha->total }}</td>
                        <td>{{ (int) $linha->ativos }}</td>
                        <td>{{ (int) $linha->com_aceite }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">Nenhuma cidade ainda.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="dica">Mostra até 200 cidades, das que têm mais promotores ativos.</p>

    <h2 style="margin-top:24px">Agências</h2>
    <div class="tabela-rolagem">
        <table>
            <thead><tr><th>Agência</th><th>Promotores ativos</th><th>Com aceite</th><th>Rede de jobs</th></tr></thead>
            <tbody>
                @forelse ($agencias as $a)
                    <tr>
                        <td>{{ $a['nome'] }}</td>
                        <td>{{ $a['ativos'] }}</td>
                        <td>{{ $a['com_aceite'] }}</td>
                        <td>
                            <span @class(['selo', 'ok' => $a['habilitada'], 'off' => ! $a['habilitada']])>{{ $a['habilitada'] ? 'Liberada' : 'Desligada' }}</span>
                            <a href="{{ route('central.clientes.marca', $a['id']) }}">Alterar</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4">Nenhuma agência.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
