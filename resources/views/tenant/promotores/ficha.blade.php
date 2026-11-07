<x-layout :titulo="$p->nome" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>
    @php($m = $operacional['metricas'])
    <div class="cabecalho">
        <div style="display:flex;align-items:center;gap:14px">
            @if ($p->foto)
                <img src="{{ route('promotores.foto', $p->id) }}" alt="" style="width:64px;height:64px;border-radius:50%;object-fit:cover">
            @else
                <span class="avatar-iniciais" aria-hidden="true" style="width:64px;height:64px;font-size:26px">{{ mb_strtoupper(mb_substr($p->nome, 0, 1)) }}</span>
            @endif
            <div>
                <h1>{{ $p->nome }}</h1>
                <p class="texto-apoio">
                    {{ $p->tipo_vinculo?->label() ?? 'Vínculo não informado' }}
                    @if ($p->cidade_base) · {{ $p->cidade_base }}{{ $p->uf_base ? ' / '.$p->uf_base : '' }} @endif
                    · CPF {{ $p->cpfMascarado() }}
                </p>
                <p>
                    @if (! $p->ativo)
                        <span class="selo off">Inativo</span>
                    @elseif ($afastadoHoje)
                        <span class="selo alerta">Afastado hoje: {{ $afastadoHoje->rotulo() }} até {{ $afastadoHoje->fim->format('d/m/Y') }}</span>
                    @elseif (! $p->conviteAceito())
                        <span class="selo alerta">Convite pendente</span>
                    @else
                        <span class="selo ok">Ativo</span>
                    @endif
                </p>
            </div>
        </div>
        <div class="acoes">
            <a class="botao secundario" href="{{ route('promotores.index') }}">Voltar à lista</a>
            <a class="botao secundario" href="{{ route('promotores.cracha', $p->id) }}">Crachá</a>
            @if ($podeGerir)
                <a class="botao" href="{{ route('promotores.edit', $p->id) }}">Editar cadastro</a>
            @endif
        </div>
    </div>

    <div class="cartao">
        <h2>Últimos 30 dias</h2>
        <div class="linha">
            <div class="campo"><div class="rotulo">Visitas previstas</div><strong>{{ $m['previstas'] }}</strong></div>
            <div class="campo"><div class="rotulo">Realizadas</div><strong>{{ $m['realizadas'] }}</strong></div>
            <div class="campo"><div class="rotulo">Não executadas</div><strong>{{ $m['nao_executadas'] }}</strong></div>
            <div class="campo"><div class="rotulo">Impedimentos</div><strong>{{ $m['impedimentos'] }}</strong></div>
            <div class="campo"><div class="rotulo">Rupturas</div><strong>{{ $m['rupturas'] }}</strong></div>
            <div class="campo"><div class="rotulo">Tempo médio</div><strong>{{ $m['tempo_medio_min'] !== null ? $m['tempo_medio_min'].' min' : '—' }}</strong></div>
        </div>
    </div>

    <div class="cartao">
        <h2>Agenda dos próximos 7 dias</h2>
        <p class="dica">Soma das indústrias que a agência atende.</p>
        @forelse ($operacional['agenda'] as $dia => $visitas)
            <h3>{{ \Illuminate\Support\Carbon::parse($dia)->translatedFormat('l, d/m') }}</h3>
            <div class="tabela-rolagem">
                <table>
                    <tbody>
                        @foreach ($visitas as $v)
                            <tr>
                                <td>{{ $v->pdv }}@if ($v->cidade)<div class="rotulo">{{ $v->cidade }}</div>@endif</td>
                                <td>{{ $v->industria }}</td>
                                <td>{{ $v->situacao }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <p>Nenhuma visita atribuída nos próximos 7 dias.</p>
        @endforelse
    </div>

    <div class="cartao">
        <h2>Histórico de visitas</h2>
        <div class="tabela-rolagem">
            <table>
                <thead><tr><th>Dia</th><th>PDV</th><th>Indústria</th><th>Situação</th><th>Duração</th></tr></thead>
                <tbody>
                    @forelse ($operacional['historico']->take(60) as $v)
                        <tr>
                            <td style="white-space:nowrap">{{ \Illuminate\Support\Carbon::parse($v->data)->format('d/m/Y') }}</td>
                            <td>{{ $v->pdv }}</td>
                            <td>{{ $v->industria }}</td>
                            <td>{{ $v->situacao }}@if ($v->tipo_conclusao === 'ruptura') <span class="rotulo">(ruptura)</span>@elseif ($v->tipo_conclusao === 'impedimento') <span class="rotulo">(impedimento)</span>@endif</td>
                            <td>{{ $v->duracao_segundos ? round($v->duracao_segundos / 60).' min' : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">Sem visitas nos últimos 30 dias.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="cartao">
        <h2>Indústrias em que atua</h2>
        <p class="dica">Só nas marcadas ele recebe visitas e vê o roteiro no app. Nenhuma marcada = todas as indústrias que a agência atende.</p>
        @if ($industrias->isEmpty())
            <p>A agência ainda não está vinculada a nenhuma indústria.</p>
        @elseif ($podeGerir)
            <form method="POST" action="{{ route('promotores.industrias', $p->id) }}">
                @csrf @method('PUT')
                <div class="campo">
                    @foreach ($industrias as $i)
                        <label class="check"><input type="checkbox" name="industrias[]" value="{{ $i->id }}" @checked(in_array($i->id, $habilitadas, true))> {{ $i->nome ?? $i->id }}</label>
                    @endforeach
                </div>
                <button type="submit" class="botao">Salvar</button>
            </form>
        @else
            <p>{{ $habilitadas === [] ? 'Todas as indústrias atendidas.' : $industrias->whereIn('id', $habilitadas)->map(fn ($i) => $i->nome ?? $i->id)->implode(', ') }}</p>
        @endif
    </div>

    <div class="cartao">
        <h2>Afastamentos</h2>
        <p class="dica">Férias, atestado e folgas. Avise a gestão antes de atribuir visitas nesses dias.</p>
        <div class="tabela-rolagem">
            <table>
                <thead><tr><th>Tipo</th><th>Período</th><th>Observação</th><th></th></tr></thead>
                <tbody>
                    @forelse ($afastamentos as $a)
                        <tr>
                            <td>{{ $a->rotulo() }}</td>
                            <td style="white-space:nowrap">{{ $a->inicio->format('d/m/Y') }} a {{ $a->fim->format('d/m/Y') }}</td>
                            <td>{{ $a->observacao ?? '—' }}</td>
                            <td>
                                @if ($podeGerir)
                                    <form method="POST" action="{{ route('promotores.afastamento.remover', [$p->id, $a->id]) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="botao secundario" onclick="return confirm('Remover este afastamento?')">Remover</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4">Nenhum afastamento registrado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($podeGerir)
            <form method="POST" action="{{ route('promotores.afastar', $p->id) }}">
                @csrf
                <div class="linha">
                    <div class="campo">
                        <label for="af-tipo">Tipo</label>
                        <select id="af-tipo" name="tipo" required>
                            @foreach ($tiposAfastamento as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="campo"><label for="af-inicio">Início</label><input type="date" id="af-inicio" name="inicio" value="{{ old('inicio') }}" required></div>
                    <div class="campo"><label for="af-fim">Fim</label><input type="date" id="af-fim" name="fim" value="{{ old('fim') }}" required></div>
                </div>
                <div class="campo"><label for="af-obs">Observação (opcional)</label><input type="text" id="af-obs" name="observacao" maxlength="300" value="{{ old('observacao') }}"></div>
                @if ($errors->hasAny(['tipo', 'inicio', 'fim', 'observacao']))
                    <p class="erro">{{ $errors->first() }}</p>
                @endif
                <button type="submit" class="botao">Registrar afastamento</button>
            </form>
        @endif
    </div>

    <div class="cartao">
        <h2>Ocorrências</h2>
        <p class="dica">Registro interno da agência. A indústria e os supermercados não veem.</p>
        <div class="tabela-rolagem">
            <table>
                <thead><tr><th>Data</th><th>Tipo</th><th>Descrição</th></tr></thead>
                <tbody>
                    @forelse ($ocorrencias as $o)
                        <tr>
                            <td style="white-space:nowrap">{{ $o->data->format('d/m/Y') }}</td>
                            <td>{{ $o->rotulo() }}</td>
                            <td style="white-space:pre-line">{{ $o->descricao }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3">Nenhuma ocorrência registrada.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($podeGerir)
            <form method="POST" action="{{ route('promotores.ocorrencia', $p->id) }}">
                @csrf
                <div class="linha">
                    <div class="campo">
                        <label for="oc-tipo">Tipo</label>
                        <select id="oc-tipo" name="tipo" required>
                            @foreach ($tiposOcorrencia as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="campo"><label for="oc-data">Data</label><input type="date" id="oc-data" name="data" value="{{ old('data', now()->toDateString()) }}" required></div>
                </div>
                <div class="campo"><label for="oc-desc">O que aconteceu</label><textarea id="oc-desc" name="descricao" rows="3" maxlength="2000" required>{{ old('descricao') }}</textarea></div>
                @if ($errors->hasAny(['descricao', 'data']))
                    <p class="erro">{{ $errors->first() }}</p>
                @endif
                <button type="submit" class="botao">Registrar ocorrência</button>
            </form>
        @endif
    </div>

    <div class="cartao">
        <h2>Aparelhos conectados</h2>
        <p class="dica">Cada aparelho em que o promotor entrou no app. Desconectar obriga a entrar de novo.</p>
        <div class="tabela-rolagem">
            <table>
                <thead><tr><th>Aparelho</th><th>Entrou em</th><th>Último uso</th><th></th></tr></thead>
                <tbody>
                    @forelse ($aparelhos as $t)
                        <tr>
                            <td>{{ $t->name }}</td>
                            <td>{{ $t->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td>{{ $t->last_used_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td>
                                @if ($podeGerir)
                                    <form method="POST" action="{{ route('promotores.aparelho.revogar', [$p->id, $t->id]) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="botao secundario" onclick="return confirm('Desconectar este aparelho?')">Desconectar</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4">Nenhum aparelho conectado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layout>
