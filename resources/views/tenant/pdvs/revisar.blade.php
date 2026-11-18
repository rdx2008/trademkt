<x-layout titulo="Revisar importação de PDVs" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    <div class="cabecalho">
        <h1>Revisar importação</h1>
        <form method="POST" action="{{ route('pdvs.importar.descartar', $id) }}">
            @csrf
            @method('DELETE')
            <button type="submit" class="botao secundario">Descartar</button>
        </form>
    </div>

    <p>
        Arquivo <strong>{{ $arquivo }}</strong>:
        {{ $resumo['novo'] }} novo(s), {{ $resumo['atualizar'] }} a atualizar, {{ $resumo['erro'] }} com erro.
        Linhas com erro ficam em amarelo e não podem ser gravadas: corrija a planilha e envie de novo.
    </p>

    <form method="POST" action="{{ route('pdvs.importar.gravar', $id) }}">
        @csrf

        <div class="tabela-rolagem">
            <table>
                <thead>
                    <tr>
                        <th><input type="checkbox" id="todas" checked aria-label="Selecionar todas"></th>
                        <th>Linha</th>
                        <th>Ação</th>
                        <th>CNPJ</th>
                        <th>Nome</th>
                        <th>Rede / canal</th>
                        <th>Endereço</th>
                        <th>Região</th>
                        <th>Coordenadas</th>
                        <th>Raio</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($linhas as $linha)
                        @php($d = $linha['dados'])
                        <tr @class(['alerta' => $linha['acao'] === 'erro'])>
                            <td>
                                @if ($linha['acao'] !== 'erro')
                                    <input type="checkbox" name="linhas[]" value="{{ $linha['linha'] }}" class="linha-sel" checked>
                                @endif
                            </td>
                            <td>{{ $linha['linha'] }}</td>
                            <td>
                                @if ($linha['acao'] === 'erro')
                                    <span class="selo alerta">Erro</span>
                                    <ul class="erros-linha">
                                        @foreach ($linha['erros'] as $erro)
                                            <li>{{ $erro }}</li>
                                        @endforeach
                                    </ul>
                                @elseif ($linha['acao'] === 'atualizar')
                                    <span class="selo">Atualizar</span>
                                @else
                                    <span class="selo ok">Novo</span>
                                @endif
                            </td>
                            <td>{{ \App\Rules\Cnpj::formatar($d['cnpj']) }}</td>
                            <td>{{ $d['nome'] }}</td>
                            <td>{{ $d['rede'] ?? '—' }}<div class="rotulo">{{ \App\Enums\CanalPdv::tryFrom((string) $d['canal'])?->label() ?? $d['canal'] }}</div></td>
                            <td>
                                {{ trim(implode(', ', array_filter([$d['logradouro'], $d['numero']]))) ?: '—' }}
                                <div class="rotulo">{{ implode(' · ', array_filter([$d['bairro'], trim(($d['cidade'] ?? '').' - '.($d['uf'] ?? ''), ' -'), $d['cep']])) }}</div>
                            </td>
                            <td>{{ $d['regiao'] ?? '—' }}</td>
                            <td>{{ $d['lat'] !== null ? $d['lat'].', '.$d['lng'] : 'Localizar' }}</td>
                            <td>{{ $d['raio_m'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="acoes" style="margin-top: 16px">
            <button type="submit" class="botao">Gravar selecionados</button>
            <a href="{{ route('pdvs.importar') }}" class="botao secundario">Enviar outra planilha</a>
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
