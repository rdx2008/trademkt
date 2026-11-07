@php($editando = $pdv->exists)
<x-layout :titulo="$editando ? 'Editar PDV' : 'Novo PDV'" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    @push('head')
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
              integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    @endpush

    <h1>{{ $editando ? 'Editar PDV' : 'Novo PDV' }}</h1>

    <form method="POST" action="{{ $editando ? route('pdvs.update', $pdv) : route('pdvs.store') }}" class="cartao">
        @csrf
        @if ($editando) @method('PUT') @endif

        <div class="linha">
            <div class="campo">
                <label for="cnpj">CNPJ</label>
                <input type="text" id="cnpj" name="cnpj" value="{{ old('cnpj', $editando ? $pdv->cnpjFormatado() : '') }}" required inputmode="numeric">
            </div>
            <div class="campo">
                <label for="nome">Nome da loja</label>
                <input type="text" id="nome" name="nome" value="{{ old('nome', $pdv->nome) }}" required maxlength="150">
            </div>
        </div>

        <div class="linha">
            <div class="campo">
                <label for="rede_id">Rede de supermercados</label>
                <select id="rede_id" name="rede_id">
                    <option value="">—</option>
                    @foreach ($redes as $id => $nome)
                        <option value="{{ $id }}" @selected((string) old('rede_id', $pdv->rede_id) === (string) $id)>{{ $nome }}</option>
                    @endforeach
                </select>
            </div>
            <div class="campo">
                <label for="canal">Canal</label>
                <select id="canal" name="canal">
                    <option value="">—</option>
                    @foreach ($canais as $valor => $rotulo)
                        <option value="{{ $valor }}" @selected(old('canal', $pdv->canal?->value) === $valor)>{{ $rotulo }}</option>
                    @endforeach
                </select>
            </div>
            <div class="campo">
                <label for="regiao_id">Região</label>
                <select id="regiao_id" name="regiao_id">
                    <option value="">—</option>
                    @foreach ($regioes as $id => $nome)
                        <option value="{{ $id }}" @selected((string) old('regiao_id', $pdv->regiao_id) === (string) $id)>{{ $nome }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="linha">
            <div class="campo">
                <label for="cep">CEP</label>
                <input type="text" id="cep" name="cep" value="{{ old('cep', $pdv->cep) }}" inputmode="numeric" maxlength="9" placeholder="00000-000" autocomplete="postal-code">
                <div class="dica" id="cep-aviso">Ao digitar o CEP, o endereço e a posição no mapa são preenchidos sozinhos.</div>
            </div>
            <div class="campo">
                <label for="logradouro">Logradouro</label>
                <input type="text" id="logradouro" name="logradouro" value="{{ old('logradouro', $pdv->logradouro) }}" maxlength="150">
            </div>
            <div class="campo">
                <label for="numero">Número</label>
                <input type="text" id="numero" name="numero" value="{{ old('numero', $pdv->numero) }}" maxlength="20">
            </div>
        </div>

        <div class="linha">
            <div class="campo">
                <label for="complemento">Complemento</label>
                <input type="text" id="complemento" name="complemento" value="{{ old('complemento', $pdv->complemento) }}" maxlength="80">
            </div>
            <div class="campo">
                <label for="bairro">Bairro</label>
                <input type="text" id="bairro" name="bairro" value="{{ old('bairro', $pdv->bairro) }}" maxlength="80">
            </div>
            <div class="campo">
                <label for="cidade">Cidade</label>
                <input type="text" id="cidade" name="cidade" value="{{ old('cidade', $pdv->cidade) }}" required maxlength="80">
            </div>
            <div class="campo">
                <label for="uf">UF</label>
                <input type="text" id="uf" name="uf" value="{{ old('uf', $pdv->uf) }}" required maxlength="2">
            </div>
        </div>

        <h2>Localização e check-in</h2>
        <p class="dica">
            @if ($geocodingDisponivel)
                Latitude e longitude são preenchidas automaticamente pelo endereço. Se o pino não cair na porta
                da loja, arraste-o (ou clique no mapa) e salve: o ajuste manual prevalece.
            @else
                Localização automática desligada nesta instalação: clique no mapa ou arraste o pino até a loja.
            @endif
        </p>
        <div id="mapa" class="mapa"></div>

        <div class="linha">
            <div class="campo">
                <label for="lat">Latitude</label>
                <input type="number" id="lat" name="lat" step="any" value="{{ old('lat', $pdv->lat) }}">
            </div>
            <div class="campo">
                <label for="lng">Longitude</label>
                <input type="number" id="lng" name="lng" step="any" value="{{ old('lng', $pdv->lng) }}">
            </div>
            <div class="campo">
                <label for="raio_m">Raio de check-in (m)</label>
                <input type="number" id="raio_m" name="raio_m" min="{{ \App\Models\Pdv::RAIO_MINIMO }}" max="{{ \App\Models\Pdv::RAIO_MAXIMO }}" step="1"
                       value="{{ old('raio_m', $pdv->raio_m ?? \App\Models\Pdv::RAIO_PADRAO) }}">
                <div class="dica">Entre {{ \App\Models\Pdv::RAIO_MINIMO }} e {{ \App\Models\Pdv::RAIO_MAXIMO }} m. Padrão: {{ \App\Models\Pdv::RAIO_PADRAO }} m.</div>
            </div>
        </div>

        <div class="campo">
            <input type="hidden" name="ativo" value="0">
            <label class="check"><input type="checkbox" name="ativo" value="1" @checked(old('ativo', $pdv->ativo ?? true))> Ativo</label>
        </div>

        <button type="submit" class="botao">Salvar</button>
        <a href="{{ route('pdvs.index') }}" class="botao secundario">Cancelar</a>
    </form>

    @push('scripts')
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
                integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
        <script>
            (function () {
                if (!window.L) return;

                var campoLat = document.getElementById('lat');
                var campoLng = document.getElementById('lng');
                var campoRaio = document.getElementById('raio_m');
                var temPonto = campoLat.value !== '' && campoLng.value !== '';
                var centro = temPonto ? [parseFloat(campoLat.value), parseFloat(campoLng.value)] : [-15.78, -47.93];

                var mapa = L.map('mapa').setView(centro, temPonto ? 17 : 4);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap'
                }).addTo(mapa);

                var pino = null;
                var circulo = null;
                // Posição ajustada à mão (pino arrastado, clique no mapa ou coordenadas digitadas): a busca automática não a troca.
                var manual = {{ ($pdv->coordenadas_origem ?? null) === 'manual' ? 'true' : 'false' }};

                function raio() {
                    var r = parseInt(campoRaio.value, 10);
                    return isNaN(r) ? {{ \App\Models\Pdv::RAIO_PADRAO }} : r;
                }

                function posicionar(latlng, atualizarCampos) {
                    if (!pino) {
                        pino = L.marker(latlng, { draggable: true }).addTo(mapa);
                        circulo = L.circle(latlng, { radius: raio(), color: '#c62828', weight: 1, fillOpacity: 0.1 }).addTo(mapa);
                        pino.on('drag', function (e) { circulo.setLatLng(e.target.getLatLng()); });
                        pino.on('dragend', function (e) { manual = true; gravar(e.target.getLatLng()); });
                    } else {
                        pino.setLatLng(latlng);
                        circulo.setLatLng(latlng);
                    }
                    if (atualizarCampos) gravar(pino.getLatLng());
                }

                function gravar(latlng) {
                    campoLat.value = latlng.lat.toFixed(7);
                    campoLng.value = latlng.lng.toFixed(7);
                }

                if (temPonto) posicionar(centro, false);

                mapa.on('click', function (e) { manual = true; posicionar(e.latlng, true); });
                // Vem do preenchimento por CEP/endereço: move o pino, a menos que já tenha sido ajustado à mão.
                document.addEventListener('pdv:ponto', function (e) {
                    if (manual) return;
                    posicionar([e.detail.lat, e.detail.lng], true);
                    mapa.setView([e.detail.lat, e.detail.lng], 17);
                });
                campoRaio.addEventListener('input', function () { if (circulo) circulo.setRadius(raio()); });
                [campoLat, campoLng].forEach(function (campo) {
                    campo.addEventListener('change', function () {
                        manual = true;
                        var lat = parseFloat(campoLat.value), lng = parseFloat(campoLng.value);
                        if (!isNaN(lat) && !isNaN(lng)) { posicionar([lat, lng], false); mapa.setView([lat, lng], 17); }
                    });
                });
            })();
        </script>
        <script>
            // CEP → endereço, e endereço → coordenadas (campos e pino do mapa).
            (function () {
                var campo = function (id) { return document.getElementById(id); };
                var cep = campo('cep'), aviso = campo('cep-aviso');
                var urlCep = @json(route('pdvs.cep', ['cep' => '00000000']));
                var urlLocalizar = @json(route('pdvs.localizar'));
                var textoAviso = aviso.textContent;
                var ultimoCep = (cep.value || '').replace(/\D/g, '');
                var pedido = 0;

                function dizer(texto, erro) { aviso.textContent = texto; aviso.style.color = erro ? '#c62828' : ''; }

                function localizar() {
                    if (!campo('cidade').value || !campo('uf').value) return;
                    var consulta = new URLSearchParams({
                        logradouro: campo('logradouro').value, numero: campo('numero').value, bairro: campo('bairro').value,
                        cidade: campo('cidade').value, uf: campo('uf').value, cep: cep.value
                    });
                    var este = ++pedido;
                    fetch(urlLocalizar + '?' + consulta, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                        .then(function (r) { return r.ok ? r.json() : null; })
                        .then(function (ponto) {
                            if (este !== pedido || !ponto) return;
                            campo('lat').value = ponto.lat; campo('lng').value = ponto.lng;
                            document.dispatchEvent(new CustomEvent('pdv:ponto', { detail: ponto }));
                        })
                        .catch(function () {});
                }

                function buscarCep() {
                    var digitos = cep.value.replace(/\D/g, '');
                    if (digitos.length !== 8 || digitos === ultimoCep) return;
                    ultimoCep = digitos;
                    cep.value = digitos.slice(0, 5) + '-' + digitos.slice(5);
                    dizer('Buscando o endereço...');
                    fetch(urlCep.replace('00000000', digitos), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                        .then(function (res) {
                            if (!res.ok) { dizer(res.j.message || 'CEP não encontrado.', true); return; }
                            var e = res.j;
                            if (e.logradouro) campo('logradouro').value = e.logradouro;
                            if (e.bairro) campo('bairro').value = e.bairro;
                            campo('cidade').value = e.cidade; campo('uf').value = e.uf;
                            dizer(textoAviso);
                            campo(e.logradouro ? 'numero' : 'logradouro').focus();
                            if (e.lat !== null && e.lng !== null && !campo('lat').value) {
                                campo('lat').value = e.lat; campo('lng').value = e.lng;
                                document.dispatchEvent(new CustomEvent('pdv:ponto', { detail: { lat: e.lat, lng: e.lng } }));
                            }
                            localizar();
                        })
                        .catch(function () { dizer('Não foi possível consultar o CEP agora. Preencha o endereço manualmente.', true); });
                }

                cep.addEventListener('input', buscarCep);
                cep.addEventListener('blur', buscarCep);
                ['numero', 'logradouro', 'bairro', 'cidade', 'uf'].forEach(function (id) { campo(id).addEventListener('change', localizar); });
            })();
        </script>
    @endpush
</x-layout>
