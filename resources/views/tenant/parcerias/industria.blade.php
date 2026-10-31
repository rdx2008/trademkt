<x-layout titulo="Buscar agências" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>
    <div class="painel-cabecalho"><div><div class="sobretitulo">INDÚSTRIA / PARCERIAS</div><h1>Encontre sua agência</h1><p class="texto-apoio">Busque uma prestadora, envie uma solicitação e aguarde o aceite. Os contratos de PDVs são definidos depois.</p></div></div>
    <form class="filtros" method="GET" action="{{ route('parcerias.index') }}">
        <label class="sr-only" for="filtro-uf">Estado</label><select id="filtro-uf" name="uf"><option value="">Todos os estados</option>@foreach (\App\Services\Rede\PerfilAgencia::ESTADOS as $uf => $nome)<option value="{{ $uf }}" @selected(request('uf') === $uf)>{{ $nome }} ({{ $uf }})</option>@endforeach</select>
        <label class="sr-only" for="filtro-cidade">Município</label><input type="search" id="filtro-cidade" name="cidade" value="{{ request('cidade') }}" placeholder="Município atendido"><button class="botao">Buscar</button><a class="botao secundario" href="{{ route('parcerias.index') }}">Limpar</a>
    </form>
    <div class="grade lojas-grade">
        @forelse ($agencias as $agencia)
            <article class="cartao"><span class="selo ok">Agência aprovada</span><h2 style="margin-top:12px">{{ $agencia->tenant->nome }}</h2><p>{{ $agencia->apresentacao }}</p>
                <p class="rotulo">@foreach ($agencia->regioes as $regiao)<span class="selo">{{ $regiao->uf }}{{ $regiao->cidade ? ' · '.$regiao->cidade : ' · Todo o estado' }}</span> @endforeach</p>
                @if ($agencia->email_comercial)<p><a href="mailto:{{ $agencia->email_comercial }}">{{ $agencia->email_comercial }}</a></p>@endif
                @php($existente = $parcerias->firstWhere('agencia_rede_id', $agencia->id))
                @if ($existente && in_array($existente->status, ['pendente', 'aceita']))
                    <p class="selo">{{ $existente->status === 'aceita' ? 'Parceria aceita' : 'Aguardando resposta da agência' }}</p>
                @else
                    <form method="POST" action="{{ route('parcerias.solicitar') }}">
                        @csrf<input type="hidden" name="agencia_rede_id" value="{{ $agencia->id }}">
                        @include('components.estado-municipio', ['prefixo' => 'pedido-'.$agencia->id, 'ufAtual' => old('agencia_rede_id') == $agencia->id ? old('uf') : request('uf'), 'cidadeAtual' => old('agencia_rede_id') == $agencia->id ? old('cidade') : request('cidade')])
                        <div class="campo"><label for="mensagem-{{ $agencia->id }}">Mensagem para a agência</label><textarea id="mensagem-{{ $agencia->id }}" name="mensagem" rows="2" maxlength="2000">{{ old('agencia_rede_id') == $agencia->id ? old('mensagem') : '' }}</textarea></div>
                        <button class="botao">Solicitar parceria</button>
                    </form>
                @endif
            </article>
        @empty
            <div class="cartao estado-vazio"><h2>Nenhuma agência disponível para esta busca</h2><p>Altere a região ou consulte novamente após novas aprovações.</p></div>
        @endforelse
    </div>
    {{ $agencias->links('components.paginacao') }}
    <section class="cartao secao-painel"><h2>Minhas solicitações e parcerias</h2>
        <p class="rotulo">Você pode atualizar o estado e o município de interesse nas solicitações pendentes e nas parcerias aceitas. Isso não altera contratos, PDVs ou roteiros.</p>
        <div class="tabela-rolagem"><table><thead><tr><th>Agência</th><th>Região desejada</th><th>Situação</th><th>Resposta</th><th>Ações</th></tr></thead><tbody>
            @forelse ($parcerias as $parceria)
                <tr><td>{{ $parceria->agencia->tenant->nome }}</td><td>{{ $parceria->regiao }}</td><td>{{ match ($parceria->status) { 'aceita' => 'Aceita', 'recusada' => 'Recusada', 'cancelada' => 'Cancelada', default => 'Aguardando resposta' } }}</td><td style="white-space:normal">{{ $parceria->resposta ?? '—' }}</td><td>
                    @if (in_array($parceria->status, ['pendente', 'aceita']))
                        <details @if(old('parceria_regiao_id') == $parceria->id) open @endif><summary>Editar região</summary>
                            <form class="cartao" style="margin-top:12px;min-width:280px;white-space:normal" method="POST" action="{{ route('parcerias.regiao', $parceria) }}">
                                @csrf @method('PATCH')<input type="hidden" name="parceria_regiao_id" value="{{ $parceria->id }}">
                                @unless ($parceria->uf)<p class="dica">Região cadastrada anteriormente: {{ $parceria->regiao }}. Selecione o estado para atualizar.</p>@endunless
                                @include('components.estado-municipio', ['prefixo' => 'editar-'.$parceria->id, 'ufAtual' => old('parceria_regiao_id') == $parceria->id ? old('uf') : $parceria->uf, 'cidadeAtual' => old('parceria_regiao_id') == $parceria->id ? old('cidade') : $parceria->cidade])
                                <button class="botao">Salvar região</button>
                            </form>
                        </details>
                    @endif
                    @if ($parceria->status === 'pendente')<form style="margin-top:8px" method="POST" action="{{ route('parcerias.cancelar', $parceria) }}">@csrf @method('DELETE')<button class="link-botao">Cancelar solicitação</button></form>@elseif ($parceria->status === 'aceita')<a href="{{ route('contratos.index') }}">Consultar contratos</a>@endif
                </td></tr>
            @empty
                <tr><td colspan="5">Você ainda não enviou solicitações. Escolha uma agência no diretório acima.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>
</x-layout>
