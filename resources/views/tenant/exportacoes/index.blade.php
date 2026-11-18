<x-layout titulo="Fila de downloads" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>
    <div class="cabecalho">
        <div>
            <h1>Fila de downloads</h1>
            <p class="texto-apoio">Exportações grandes geradas em segundo plano. Cada arquivo fica disponível por {{ \App\Models\Exportacao::DIAS_DE_VALIDADE }} dias, só para você.</p>
        </div>
        <a class="botao secundario" href="{{ route('exportacoes.index') }}">Atualizar</a>
    </div>

    <div class="tabela-rolagem">
        <table>
            <thead><tr><th>Pedida em</th><th>Exportação</th><th>Situação</th><th>Tamanho</th><th>Vale até</th><th></th></tr></thead>
            <tbody>
                @forelse ($exportacoes as $e)
                    <tr>
                        <td style="white-space:nowrap">{{ $e->created_at->format('d/m/Y H:i') }}</td>
                        <td>{{ $e->rotuloTipo() }}@if ($e->nome)<div class="rotulo">{{ $e->nome }}</div>@endif</td>
                        <td>
                            @if ($e->estado === 'pronto' && $e->expirou())<span class="selo off">Expirada</span>
                            @elseif ($e->estado === 'pronto')<span class="selo ok">Pronta</span>
                            @elseif ($e->estado === 'erro')<span class="selo off">Erro</span>@if ($e->erro)<div class="rotulo">{{ $e->erro }}</div>@endif
                            @else<span class="selo alerta">Gerando…</span>@endif
                        </td>
                        <td>{{ $e->tamanho ? number_format($e->tamanho / 1024, 0, ',', '.').' KB' : '—' }}</td>
                        <td>{{ $e->expira_em?->format('d/m/Y') ?? '—' }}</td>
                        <td style="white-space:nowrap">
                            @if ($e->disponivel())<a href="{{ route('exportacoes.baixar', $e->id) }}">Baixar</a>@endif
                            <form method="POST" action="{{ route('exportacoes.destroy', $e->id) }}" style="display:inline">@csrf @method('DELETE')<button type="submit" class="link-botao">Remover</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">Nenhuma exportação pedida. Use "Gerar em segundo plano" nos indicadores e nos resultados das coletas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
