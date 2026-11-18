@php($editando = $contrato->exists)
@php($diasMarcados = array_map('intval', (array) old('dias', $contrato->dias ?? [])))
<x-layout :titulo="$editando ? 'Editar contrato' : 'Novo contrato'" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    <h1>{{ $editando ? 'Editar contrato de visita' : 'Novo contrato de visita' }}</h1>

    <form method="POST" action="{{ $editando ? route('contratos.update', $contrato) : route('contratos.store') }}" class="cartao">
        @csrf
        @if ($editando) @method('PUT') @endif

        <div class="linha">
            <div class="campo">
                <label for="pdv_id">PDV</label>
                <select id="pdv_id" name="pdv_id" required>
                    <option value="">—</option>
                    @foreach ($pdvs as $pdv)
                        <option value="{{ $pdv->id }}" @selected((string) old('pdv_id', $contrato->pdv_id) === (string) $pdv->id)>{{ $pdv->nome }} — {{ $pdv->cidade }}/{{ $pdv->uf }}</option>
                    @endforeach
                </select>
            </div>
            <div class="campo">
                <label for="industria_id">Indústria</label>
                <select id="industria_id" name="industria_id" required>
                    <option value="">—</option>
                    @foreach ($industrias as $id => $nome)
                        <option value="{{ $id }}" @selected((string) old('industria_id', $contrato->industria_id ?? ($industrias->count() === 1 ? $id : null)) === (string) $id)>{{ $nome }}</option>
                    @endforeach
                </select>
                <div class="dica">Um PDV pode ter contratos de várias indústrias.</div>
            </div>
            @if ($eu->perfil === \App\Enums\Perfil::AdminInstalacao)
                <div class="campo">
                    <label for="agencia_id">Agência que executa</label>
                    <select id="agencia_id" name="agencia_id" required>
                        <option value="">—</option>
                        @foreach ($agencias as $id => $nome)
                            <option value="{{ $id }}" @selected((string) old('agencia_id', $contrato->agencia_id ?? ($agencias->count() === 1 ? $id : null)) === (string) $id)>{{ $nome }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>

        <div class="linha">
            <div class="campo">
                <label for="frequencia_semana">Frequência</label>
                <select id="frequencia_semana" name="frequencia_semana" required>
                    @foreach (\App\Models\ContratoVisita::FREQUENCIAS as $f)
                        <option value="{{ $f }}" @selected((string) old('frequencia_semana', $contrato->frequencia_semana) === (string) $f)>{{ $f }}x por semana</option>
                    @endforeach
                </select>
            </div>
            <div class="campo">
                <label>Dias da semana</label>
                <div class="acoes" id="dias">
                    @foreach ($diasSemana as $numero => $rotulo)
                        <label class="check"><input type="checkbox" name="dias[]" value="{{ $numero }}" @checked(in_array($numero, $diasMarcados, true))> {{ $rotulo }}</label>
                    @endforeach
                </div>
                <div class="dica" id="dica-dias">Marque um dia para cada visita da frequência.</div>
            </div>
        </div>

        <div class="linha">
            <div class="campo">
                <label for="inicio">Início da vigência</label>
                <input type="date" id="inicio" name="inicio" value="{{ old('inicio', $contrato->inicio?->toDateString()) }}" required>
            </div>
            <div class="campo">
                <label for="fim">Fim da vigência</label>
                <input type="date" id="fim" name="fim" value="{{ old('fim', $contrato->fim?->toDateString()) }}">
                <div class="dica">Em branco: sem data de término.</div>
            </div>
        </div>

        <div class="campo">
            <input type="hidden" name="ativo" value="0">
            <label class="check"><input type="checkbox" name="ativo" value="1" @checked(old('ativo', $contrato->ativo ?? true))> Ativo</label>
        </div>

        <button type="submit" class="botao">Salvar</button>
        <a href="{{ route('contratos.index') }}" class="botao secundario">Cancelar</a>
    </form>

    @push('scripts')
        <script>
            (function () {
                var frequencia = document.getElementById('frequencia_semana');
                var caixas = document.querySelectorAll('#dias input[type=checkbox]');
                var dica = document.getElementById('dica-dias');

                function atualizar() {
                    var n = parseInt(frequencia.value, 10);
                    var marcados = Array.prototype.filter.call(caixas, function (c) { return c.checked; }).length;
                    caixas.forEach(function (c) { c.disabled = !c.checked && marcados >= n; });
                    dica.textContent = 'Marque ' + n + ' dia(s): ' + marcados + ' marcado(s).';
                }

                frequencia.addEventListener('change', atualizar);
                caixas.forEach(function (c) { c.addEventListener('change', atualizar); });
                atualizar();
            })();
        </script>
    @endpush
</x-layout>
