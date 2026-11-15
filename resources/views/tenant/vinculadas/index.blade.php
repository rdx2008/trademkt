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

    @if (session('convite_industria'))
        <div class="cartao">
            <h2>Link de primeiro acesso da indústria</h2>
            <p class="dica">Envie para {{ session('convite_industria')['nome'] }}. Ele cria a senha e entra como admin da indústria. Vale por 7 dias, uso único, e não aparece de novo.</p>
            <div class="campo"><input type="text" value="{{ session('convite_industria')['url'] }}" readonly onfocus="this.select()"></div>
        </div>
    @endif

    @unless ($souIndustria)
        <form method="POST" action="{{ route('vinculadas.industria') }}" class="cartao">
            @csrf
            <h2>Cadastrar indústria que ainda não usa o sistema</h2>
            <p class="dica">A agência monta e opera o cadastro dela (PDVs, catálogo, contratos e visitas). Quando a indústria quiser, a agência entrega o cadastro e ela passa a ser independente, com tudo o que já foi feito.</p>
            <div class="linha">
                <div class="campo">
                    <label for="ind-nome">Nome da indústria</label>
                    <input id="ind-nome" name="nome" value="{{ old('nome') }}" maxlength="120" required>
                    @error('nome')<div class="erro">{{ $message }}</div>@enderror
                </div>
                <div class="campo">
                    <label for="ind-cnpj">CNPJ da indústria</label>
                    <input id="ind-cnpj" name="cnpj" value="{{ old('cnpj') }}" maxlength="18" required placeholder="00.000.000/0000-00">
                    @error('cnpj')<div class="erro">{{ $message }}</div>@enderror
                </div>
                <div class="campo">
                    <label for="ind-relacao">Relação</label>
                    <select id="ind-relacao" name="relacao">
                        <option value="cliente">Cliente (já atendemos)</option>
                        <option value="prospect">Prospect (sem visitas por enquanto)</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="botao">Cadastrar indústria</button>
        </form>
    @endunless

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
            <thead><tr><th>{{ $souIndustria ? 'Agência' : 'Indústria' }}</th><th>Regiões</th><th>Situação</th><th>{{ $souIndustria ? 'Catálogo' : 'Relação' }}</th><th>Desde</th>@unless ($souIndustria)<th></th>@endunless</tr></thead>
            <tbody>
                @forelse ($vinculos as $v)
                    <tr>
                        <td>{{ $outra($v)->nome }}@if ($outra($v)->cnpjFormatado())<div class="rotulo">{{ $outra($v)->cnpjFormatado() }}</div>@endif</td>
                        <td>
                            {{ $v->regioesRotulo() }}
                            @if ($souIndustria && $v->status === \App\Models\Vinculo::ATIVO)
                                <details>
                                    <summary>Editar regiões</summary>
                                    <form method="POST" action="{{ route('vinculadas.regioes', $v->id) }}">
                                        @csrf @method('PATCH')
                                        <textarea name="regioes" rows="4" placeholder="Uma por linha: RS ou Caxias do Sul - RS" aria-label="Regiões de {{ $v->agencia->nome }}">{{ collect($v->regioes ?? [])->map(fn ($r) => $r['cidade'] ? $r['cidade'].' - '.$r['uf'] : $r['uf'])->implode("\n") }}</textarea>
                                        <div class="dica">Vazio = todas as regiões. O contrato de um PDV só aceita agência que atende o estado ou a cidade dele.</div>
                                        <button type="submit" class="botao secundario">Salvar regiões</button>
                                    </form>
                                </details>
                            @endif
                        </td>
                        <td>
                            @switch($v->status)
                                @case(\App\Models\Vinculo::ATIVO)<span class="selo ok">Ativo</span>@break
                                @case(\App\Models\Vinculo::RECUSADO)<span class="selo off">Recusado</span>@break
                                @default<span class="selo off">Encerrado</span>
                            @endswitch
                        </td>
                        <td>
                            @if ($souIndustria)
                                @if ($v->status === \App\Models\Vinculo::ATIVO)
                                    <form method="POST" action="{{ route('vinculadas.permissoes', $v->id) }}">
                                        @csrf @method('PATCH')
                                        <label class="check"><input type="checkbox" name="agencia_mantem_catalogo" value="1" @checked($v->agenciaMantemCatalogo()) onchange="this.form.submit()"> Agência mantém o catálogo</label>
                                    </form>
                                @else — @endif
                            @else
                                <form method="POST" action="{{ route('vinculadas.relacao', $v->id) }}">
                                    @csrf @method('PATCH')
                                    <select name="relacao" onchange="this.form.submit()" aria-label="Relação com {{ $v->industria->nome }}">
                                        @foreach (\App\Models\Vinculo::RELACOES as $valor => $rotulo)<option value="{{ $valor }}" @selected($v->relacao === $valor)>{{ $rotulo }}</option>@endforeach
                                    </select>
                                </form>
                                @if ($v->industria->modo === \App\Enums\ModoEmpresa::Gerida && $v->industria->dono_tenant_id === tenant('id'))<div class="rotulo">Gerida pela agência</div>@endif
                            @endif
                        </td>
                        <td>{{ ($v->decidido_em ?? $v->created_at)?->format('d/m/Y') }}</td>
                        @unless ($souIndustria)
                            <td>
                                @if ($v->industria->modo === \App\Enums\ModoEmpresa::Gerida && $v->industria->dono_tenant_id === tenant('id'))
                                    <details>
                                        <summary>Entregar à indústria</summary>
                                        <form method="POST" action="{{ route('vinculadas.entregar', $v->id) }}">
                                            @csrf
                                            <input name="admin_nome" placeholder="Responsável na indústria" maxlength="120" required>
                                            <input name="admin_email" type="email" placeholder="E-mail do responsável" maxlength="190" required>
                                            <button type="submit" class="botao secundario" onclick="return confirm('Entregar o cadastro? A indústria passa a ser independente e a agência continua vinculada.')">Entregar</button>
                                        </form>
                                    </details>
                                @endif
                            </td>
                        @endunless
                    </tr>
                @empty
                    <tr><td colspan="5">Nenhum vínculo ainda.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
