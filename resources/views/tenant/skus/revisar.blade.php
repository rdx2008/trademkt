<x-layout titulo="Revisar importação de produtos" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    <div class="cabecalho">
        <h1>Revisar importação</h1>
        <form method="POST" action="{{ route('skus.importar.descartar', $id) }}">
            @csrf
            @method('DELETE')
            <button type="submit" class="botao secundario">Descartar</button>
        </form>
    </div>

    <p>
        Arquivo <strong>{{ $arquivo }}</strong> ({{ $fonte === 'pdf' ? 'PDF' : 'planilha' }}), indústria <strong>{{ $industria }}</strong>:
        {{ $resumo['novo'] }} novo(s), {{ $resumo['atualizar'] }} a atualizar, {{ $resumo['erro'] }} com erro.
        @if ($comAviso)
            {{ $comAviso }} com aviso: podem ser gravados, mas vale conferir.
        @endif
        Itens com erro não podem ser gravados: corrija o arquivo e envie de novo.
    </p>

    @if ($fonte === 'pdf')
        <p class="dica">
            Leitura automática do PDF: confira descrições, quantidades e o tipo de cada embalagem.
            Depois da gravação, qualquer ajuste pode ser feito na edição do SKU.
        </p>
    @endif

    <form method="POST" action="{{ route('skus.importar.gravar', $id) }}">
        @csrf

        <div class="tabela-rolagem">
            <table>
                <thead>
                    <tr>
                        <th><input type="checkbox" id="todas" checked aria-label="Selecionar todos"></th>
                        <th>Origem</th>
                        <th>Ação</th>
                        <th>Código</th>
                        <th>Descrição</th>
                        <th>Embalagens (GTIN)</th>
                        <th>Observações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($itens as $item)
                        @php($d = $item['dados'])
                        <tr @class(['alerta' => $item['acao'] === 'erro' || $item['avisos'] !== []])>
                            <td>
                                @if ($item['acao'] !== 'erro')
                                    <input type="checkbox" name="itens[]" value="{{ $item['linha'] }}" class="linha-sel" checked>
                                @endif
                            </td>
                            <td>{{ $item['origem'] }}</td>
                            <td>
                                @if ($item['acao'] === 'erro')
                                    <span class="selo off">Erro</span>
                                @elseif ($item['acao'] === 'atualizar')
                                    <span class="selo">Atualizar</span>
                                @else
                                    <span class="selo ok">Novo</span>
                                @endif
                            </td>
                            <td>{{ $d['codigo'] ?: '—' }}</td>
                            <td>
                                {{ $d['descricao'] ?: '—' }}
                                @if ($d['categoria'])
                                    <div class="rotulo">{{ $d['categoria'] }}</div>
                                @endif
                            </td>
                            <td>
                                @forelse ($d['embalagens'] as $e)
                                    <div>
                                        {{ \App\Enums\TipoEmbalagem::tryFrom((string) $e['tipo'])?->label() ?? ($e['tipo'] ?: '?') }}
                                        × {{ $e['quantidade'] }}
                                        @if ($e['gtin'])
                                            · {{ $e['gtin'] }}
                                            @if (\App\Rules\Gtin::formatoValido($e['gtin']) && ! \App\Rules\Gtin::digitoCorreto($e['gtin']))
                                                <span class="selo alerta" title="Dígito verificador errado">revisar</span>
                                            @endif
                                        @endif
                                        @if ($e['codigo'])
                                            <span class="rotulo">cód. {{ $e['codigo'] }}</span>
                                        @endif
                                    </div>
                                @empty
                                    —
                                @endforelse
                            </td>
                            <td>
                                @if ($item['erros'])
                                    <ul class="erros-linha">
                                        @foreach ($item['erros'] as $erro)
                                            <li>{{ $erro }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                @foreach ($item['avisos'] as $aviso)
                                    <div class="rotulo">⚠ {{ $aviso }}</div>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="acoes" style="margin-top: 16px">
            <button type="submit" class="botao">Gravar selecionados</button>
            <a href="{{ route('skus.importar') }}" class="botao secundario">Enviar outro arquivo</a>
        </p>
    </form>

    @push('scripts')
        <script>
            document.getElementById('todas').addEventListener('change', function (e) {
                document.querySelectorAll('.linha-sel').forEach(function (c) { c.checked = e.target.checked; });
            });
        </script>
    @endpush
</x-layout>
