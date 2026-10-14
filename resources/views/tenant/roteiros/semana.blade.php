@php
    $fimSemana = $inicio->addDays(6);
    $linhas = $promotores->map(fn ($p) => ['id' => $p->id, 'nome' => $p->nome, 'inativo' => ! $p->ativo]);
    $temSemPromotor = $podeEditar || $grade->keys()->contains(fn ($k) => str_starts_with($k, '0|'));
    if ($temSemPromotor) {
        $linhas->prepend(['id' => 0, 'nome' => 'Sem promotor', 'inativo' => false]);
    }
    $nomeDia = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];
@endphp
<x-layout titulo="Roteiros" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    @push('head')
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
              integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    @endpush

    <div class="cabecalho">
        <h1>Roteiros — {{ $inicio->format('d/m') }} a {{ $fimSemana->format('d/m/Y') }}</h1>
        <div class="acoes">
            <a class="botao secundario" href="{{ route('roteiros.semana', array_filter(['semana' => $inicio->subWeek()->toDateString(), 'agencia_id' => $agencias->isNotEmpty() ? $agenciaId : null])) }}">&larr; Semana anterior</a>
            <a class="botao secundario" href="{{ route('roteiros.semana', array_filter(['agencia_id' => $agencias->isNotEmpty() ? $agenciaId : null])) }}">Esta semana</a>
            <a class="botao secundario" href="{{ route('roteiros.semana', array_filter(['semana' => $inicio->addWeek()->toDateString(), 'agencia_id' => $agencias->isNotEmpty() ? $agenciaId : null])) }}">Próxima &rarr;</a>
            @if ($podeEditar && ! $fimSemana->isPast())
                <form method="POST" action="{{ route('roteiros.gerar') }}">
                    @csrf
                    <input type="hidden" name="semana" value="{{ $inicio->toDateString() }}">
                    <button type="submit" class="botao">Gerar visitas dos contratos</button>
                </form>
            @endif
        </div>
    </div>

    @if ($agencias->count() > 1)
        <form method="GET" class="filtros">
            <input type="hidden" name="semana" value="{{ $inicio->toDateString() }}">
            <select name="agencia_id" onchange="this.form.submit()">
                @foreach ($agencias as $id => $nome)
                    <option value="{{ $id }}" @selected((int) $agenciaId === (int) $id)>{{ $nome }}</option>
                @endforeach
            </select>
            <noscript><button type="submit" class="botao secundario">Ver</button></noscript>
        </form>
    @endif

    @if ($podeEditar)
        <p class="dica">Arraste as visitas entre dias e promotores (ou toque numa visita para movê-la). A ordem dentro do dia é a ordem do roteiro do promotor.</p>
    @else
        <p class="dica">Acompanhamento. A atribuição de visitas aos promotores é feita pela agência.</p>
    @endif

    @if ($semVisitas && ! $podeEditar)
        <div class="cartao">Nenhuma visita nesta semana.</div>
    @else
        <div class="tabela-rolagem">
            <table class="grade-roteiro">
                <thead>
                    <tr>
                        <th>Promotor</th>
                        @foreach ($dias as $dia)
                            <th @class(['hoje' => $dia->isToday()])>{{ $nomeDia[$dia->isoWeekday()] }} {{ $dia->format('d/m') }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($linhas as $linha)
                        <tr>
                            <th>{{ $linha['nome'] }}@if ($linha['inativo']) <span class="selo off">Inativo</span>@endif</th>
                            @foreach ($dias as $dia)
                                @php($podeSoltar = $podeEditar && ($dia->isToday() || $dia->isFuture()))
                                <td class="celula" data-promotor="{{ $linha['id'] }}" data-data="{{ $dia->toDateString() }}" @if ($podeSoltar) data-soltar="1" @endif>
                                    @foreach ($grade->get($linha['id'].'|'.$dia->toDateString(), collect()) as $visita)
                                        @php($movel = $podeEditar && $visita->podeSerMovida())
                                        <div class="visita status-{{ $visita->status->value }}" data-id="{{ $visita->id }}"
                                             @if ($movel) draggable="true" tabindex="0" data-movel="1" @endif
                                             title="{{ $visita->pdv->nome }} — {{ $visita->industria->nome }} ({{ $visita->status->label() }})">
                                            <strong>{{ $visita->pdv->nome }}</strong>
                                            <span class="rotulo">{{ $visita->industria->nome }}@if ($visita->origem->value === 'demanda') · demanda @endif</span>
                                            @if ($visita->status->value !== 'previsto')
                                                <span class="rotulo">{{ $visita->status->label() }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="cartao" style="margin-top: 16px">
        <h2>Mapa do dia</h2>
        <form method="GET" class="filtros">
            <input type="hidden" name="semana" value="{{ $inicio->toDateString() }}">
            @if ($agencias->isNotEmpty())<input type="hidden" name="agencia_id" value="{{ $agenciaId }}">@endif
            <select name="dia">
                @foreach ($dias as $dia)
                    <option value="{{ $dia->toDateString() }}" @selected($mapa['dia'] === $dia->toDateString())>{{ $nomeDia[$dia->isoWeekday()] }} {{ $dia->format('d/m') }}</option>
                @endforeach
            </select>
            <select name="promotor">
                @foreach ($linhas as $linha)
                    <option value="{{ $linha['id'] }}" @selected($mapa['promotor'] === $linha['id'])>{{ $linha['nome'] }}</option>
                @endforeach
            </select>
            <button type="submit" class="botao secundario">Ver no mapa</button>
        </form>
        @if (empty($mapa['pontos']))
            <p class="rotulo">Nenhuma visita com coordenadas neste dia.</p>
        @else
            <div id="mapa-dia" class="mapa"></div>
            <ol>
                @foreach ($mapa['pontos'] as $ponto)
                    <li>{{ $ponto['pdv'] }} <span class="rotulo">{{ $ponto['industria'] }}</span></li>
                @endforeach
            </ol>
        @endif
    </div>

    @if ($podeEditar)
        <dialog id="mover">
            <form method="POST" id="form-mover">
                @csrf
                @method('PUT')
                <h2 id="mover-titulo">Mover visita</h2>
                <div class="campo">
                    <label for="mover-promotor">Promotor</label>
                    <select id="mover-promotor" name="promotor_id">
                        @foreach ($linhas as $linha)
                            @continue($linha['inativo'])
                            <option value="{{ $linha['id'] ?: '' }}">{{ $linha['nome'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="campo">
                    <label for="mover-data">Dia</label>
                    <select id="mover-data" name="data">
                        @foreach ($dias as $dia)
                            @continue($dia->isPast() && ! $dia->isToday())
                            <option value="{{ $dia->toDateString() }}">{{ $nomeDia[$dia->isoWeekday()] }} {{ $dia->format('d/m') }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="acoes">
                    <button type="submit" class="botao">Mover</button>
                    <button type="button" class="botao secundario" id="mover-cancelar">Cancelar</button>
                </div>
            </form>
        </dialog>
    @endif

    @push('scripts')
        @if (! empty($mapa['pontos']))
            <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
                    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
            <script>
                (function () {
                    if (!window.L) return;
                    var pontos = @json($mapa['pontos']);
                    var mapa = L.map('mapa-dia');
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(mapa);
                    var limites = [];
                    pontos.forEach(function (p) {
                        var icone = L.divIcon({ className: 'pino-numero', html: String(p.n), iconSize: [26, 26] });
                        L.marker([p.lat, p.lng], { icon: icone }).addTo(mapa).bindPopup(p.n + '. ' + p.pdv + '<br>' + p.industria);
                        L.circle([p.lat, p.lng], { radius: p.raio, color: '#c62828', weight: 1, fillOpacity: 0.08 }).addTo(mapa);
                        limites.push([p.lat, p.lng]);
                    });
                    if (limites.length > 1) L.polyline(limites, { color: '#5f6b7a', dashArray: '4 6' }).addTo(mapa);
                    mapa.fitBounds(limites, { padding: [30, 30], maxZoom: 16 });
                })();
            </script>
        @endif

        @if ($podeEditar)
            <script>
                (function () {
                    var token = document.querySelector('meta[name=csrf-token]').content;
                    var urlMover = @json(route('visitas.mover', ['visita' => '__id__']));
                    var arrastando = null;

                    function enviar(card, celula) {
                        var ordem = Array.prototype.map.call(celula.querySelectorAll('.visita'), function (v) { return parseInt(v.dataset.id, 10); });
                        var promotor = parseInt(celula.dataset.promotor, 10);
                        return fetch(urlMover.replace('__id__', card.dataset.id), {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                            body: JSON.stringify({ promotor_id: promotor || null, data: celula.dataset.data, ordem: ordem })
                        }).then(function (r) {
                            if (r.ok) return;
                            return r.json().then(function (j) {
                                var erros = j.errors ? Object.values(j.errors).flat().join('\n') : (j.message || 'Não foi possível mover.');
                                alert(erros);
                                location.reload();
                            });
                        }).catch(function () { alert('Sem conexão. A página vai recarregar.'); location.reload(); });
                    }

                    document.querySelectorAll('.visita[data-movel]').forEach(function (card) {
                        card.addEventListener('dragstart', function (e) { arrastando = card; card.classList.add('arrastando'); e.dataTransfer.effectAllowed = 'move'; });
                        card.addEventListener('dragend', function () { card.classList.remove('arrastando'); arrastando = null; });
                        card.addEventListener('click', function () { abrirDialogo(card); });
                        card.addEventListener('keydown', function (e) { if (e.key === 'Enter') abrirDialogo(card); });
                    });

                    document.querySelectorAll('.celula[data-soltar]').forEach(function (celula) {
                        celula.addEventListener('dragover', function (e) {
                            if (!arrastando) return;
                            e.preventDefault();
                            var depois = Array.prototype.find.call(celula.querySelectorAll('.visita:not(.arrastando)'), function (v) {
                                var r = v.getBoundingClientRect();
                                return e.clientY < r.top + r.height / 2;
                            });
                            celula.insertBefore(arrastando, depois || null);
                        });
                        celula.addEventListener('drop', function (e) {
                            e.preventDefault();
                            if (arrastando) enviar(arrastando, celula);
                        });
                    });

                    var dialogo = document.getElementById('mover');
                    var form = document.getElementById('form-mover');
                    function abrirDialogo(card) {
                        var celula = card.closest('.celula');
                        form.action = urlMover.replace('__id__', card.dataset.id);
                        document.getElementById('mover-titulo').textContent = card.querySelector('strong').textContent;
                        document.getElementById('mover-promotor').value = celula.dataset.promotor === '0' ? '' : celula.dataset.promotor;
                        document.getElementById('mover-data').value = celula.dataset.data;
                        dialogo.showModal();
                    }
                    document.getElementById('mover-cancelar').addEventListener('click', function () { dialogo.close(); });
                })();
            </script>
        @endif
    @endpush
</x-layout>
