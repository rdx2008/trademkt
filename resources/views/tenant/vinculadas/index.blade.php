<x-layout titulo="Empresas vinculadas" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>
    @php($outra = fn ($v) => $souIndustria ? $v->agencia : $v->industria)
    <div class="cabecalho">
        <div>
            <h1>Empresas vinculadas</h1>
            <p class="texto-apoio">{{ $souIndustria ? 'Agências que atendem esta indústria.' : 'Indústrias atendidas por esta agência.' }} O vínculo vale quando as duas empresas concordam.</p>
        </div>
        @if ($souIndustria)
            <a class="botao secundario" href="{{ route('agencias.create') }}">Criar agência nova</a>
        @endif
    </div>

    @if ($recebidos->isNotEmpty())
        <div class="cartao">
            <h2>Pedidos recebidos</h2>
            @foreach ($recebidos as $v)
                <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--borda, #eee)">
                    <div><strong>{{ $outra($v)->nome }}</strong><div class="rotulo">CNPJ {{ $outra($v)->cnpjFormatado() ?? '—' }} · pedido em {{ $v->updated_at->format('d/m/Y') }}</div></div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                        <form method="POST" action="{{ route('vinculadas.aceitar', $v->id) }}">
                            @csrf @method('PATCH')
                            @if ($souIndustria)
                                <label class="check"><input type="checkbox" name="agencia_mantem_catalogo" value="1" checked> A agência pode importar e manter o catálogo</label>
                            @endif
                            <button type="submit" class="botao">Aceitar</button>
                        </form>
                        <form method="POST" action="{{ route('vinculadas.recusar', $v->id) }}">
                            @csrf @method('PATCH')
                            <button type="submit" class="botao secundario" onclick="return confirm('Recusar o pedido de {{ addslashes($outra($v)->nome) }}?')">Recusar</button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('vinculadas.pedir') }}" class="cartao">
        @csrf
        <h2>{{ $souIndustria ? 'Pedir vínculo a uma agência que já usa o sistema' : 'Pedir vínculo a uma indústria que já usa o sistema' }}</h2>
        <div class="linha">
            <div class="campo">
                <label for="cnpj">CNPJ {{ $souIndustria ? 'da agência' : 'da indústria' }}</label>
                <input id="cnpj" name="cnpj" value="{{ old('cnpj') }}" maxlength="18" required placeholder="00.000.000/0000-00">
                @error('cnpj')<div class="erro">{{ $message }}</div>@enderror
            </div>
            @unless ($souIndustria)
                <div class="campo">
                    <label for="relacao">Relação</label>
                    <select id="relacao" name="relacao">
                        <option value="cliente">Cliente (já atendemos)</option>
                        <option value="prospect">Prospect (futuro cliente)</option>
                    </select>
                </div>
            @endunless
        </div>
        <button type="submit" class="botao">Enviar pedido</button>
    </form>

    @if ($enviados->isNotEmpty())
        <div class="cartao">
            <h2>Pedidos enviados</h2>
            @foreach ($enviados as $v)
                <div style="display:flex;gap:12px;align-items:center;justify-content:space-between;padding:6px 0">
                    <div>{{ $outra($v)->nome }} <span class="selo alerta">Aguardando resposta</span></div>
                    <form method="POST" action="{{ route('vinculadas.cancelar', $v->id) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="link-botao">Cancelar pedido</button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif

    <div class="tabela-rolagem">
        <table>
            <thead><tr><th>{{ $souIndustria ? 'Agência' : 'Indústria' }}</th><th>Situação</th>@unless ($souIndustria)<th>Relação</th>@endunless<th>Desde</th></tr></thead>
            <tbody>
                @forelse ($vinculos as $v)
                    <tr>
                        <td>{{ $outra($v)->nome }}@if ($outra($v)->cnpjFormatado())<div class="rotulo">{{ $outra($v)->cnpjFormatado() }}</div>@endif</td>
                        <td>
                            @switch($v->status)
                                @case(\App\Models\Vinculo::ATIVO)<span class="selo ok">Ativo</span>@break
                                @case(\App\Models\Vinculo::RECUSADO)<span class="selo off">Recusado</span>@break
                                @default<span class="selo off">Encerrado</span>
                            @endswitch
                        </td>
                        @unless ($souIndustria)<td>{{ \App\Models\Vinculo::RELACOES[$v->relacao] ?? '—' }}</td>@endunless
                        <td>{{ ($v->decidido_em ?? $v->created_at)?->format('d/m/Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Nenhum vínculo ainda.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
