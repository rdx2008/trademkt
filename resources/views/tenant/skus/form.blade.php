@php($editando = $sku->exists)
<x-layout :titulo="$editando ? 'Editar SKU' : 'Novo SKU'" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    <h1>{{ $editando ? 'Editar SKU' : 'Novo SKU' }}</h1>

    <form method="POST" action="{{ $editando ? route('skus.update', $sku) : route('skus.store') }}" enctype="multipart/form-data" class="cartao">
        @csrf
        @if ($editando) @method('PUT') @endif

        <div class="linha">
            @if ($eu->perfil === \App\Enums\Perfil::AdminInstalacao)
                <div class="campo">
                    <label for="industria_id">Indústria</label>
                    <select id="industria_id" name="industria_id" required>
                        <option value="">—</option>
                        @foreach ($industrias as $id => $nome)
                            <option value="{{ $id }}" @selected((string) old('industria_id', $sku->industria_id ?? ($industrias->count() === 1 ? $id : null)) === (string) $id)>{{ $nome }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="campo">
                <label for="codigo">Código</label>
                <input type="text" id="codigo" name="codigo" value="{{ old('codigo', $sku->codigo) }}" required maxlength="40">
                <div class="dica">Código do produto na indústria. Não se repete dentro da mesma indústria.</div>
            </div>
            <div class="campo">
                <label for="categoria">Categoria</label>
                <input type="text" id="categoria" name="categoria" value="{{ old('categoria', $sku->categoria) }}" maxlength="80" list="categorias">
                <datalist id="categorias">
                    @foreach ($categorias as $categoria)
                        <option value="{{ $categoria }}">
                    @endforeach
                </datalist>
            </div>
        </div>

        <div class="campo">
            <label for="descricao">Descrição</label>
            <input type="text" id="descricao" name="descricao" value="{{ old('descricao', $sku->descricao) }}" required maxlength="200">
        </div>

        <div class="linha">
            <div class="campo">
                <label for="foto">Foto</label>
                <input type="file" id="foto" name="foto" accept="image/jpeg,image/png,image/webp">
                <div class="dica">JPG, PNG ou WEBP, até 5 MB.</div>
            </div>
            @if ($sku->foto)
                <div class="campo">
                    <img src="{{ route('skus.foto', $sku) }}" alt="Foto atual" style="max-height: 96px; display: block; margin-bottom: 6px">
                    <input type="hidden" name="remover_foto" value="0">
                    <label class="check"><input type="checkbox" name="remover_foto" value="1"> Remover foto</label>
                </div>
            @endif
        </div>

        <h2>Embalagens</h2>
        <p class="dica">
            Cada embalagem tem o seu GTIN (EAN). GTIN com dígito verificador errado é aceito, mas fica marcado em amarelo
            para revisão. Linhas em branco são ignoradas.
        </p>

        <div class="tabela-rolagem">
            <table id="embalagens">
                <thead>
                    <tr><th>Tipo</th><th>Quantidade</th><th>GTIN</th><th>Código da embalagem</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($embalagens as $i => $embalagem)
                        <tr @class(['alerta' => $embalagem['alerta'] ?? false])>
                            <td>
                                @if (! empty($embalagem['id']))
                                    <input type="hidden" name="embalagens[{{ $i }}][id]" value="{{ $embalagem['id'] }}">
                                @endif
                                <select name="embalagens[{{ $i }}][tipo]">
                                    @foreach ($tipos as $valor => $rotulo)
                                        <option value="{{ $valor }}" @selected(($embalagem['tipo'] ?? '') === $valor)>{{ $rotulo }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td><input type="number" name="embalagens[{{ $i }}][quantidade]" value="{{ $embalagem['quantidade'] ?? '' }}" min="1" style="width: 100px"></td>
                            <td><input type="text" name="embalagens[{{ $i }}][gtin]" value="{{ $embalagem['gtin'] ?? '' }}" inputmode="numeric" maxlength="20"></td>
                            <td><input type="text" name="embalagens[{{ $i }}][codigo]" value="{{ $embalagem['codigo'] ?? '' }}" maxlength="40"></td>
                            <td><button type="button" class="link-botao remover-embalagem">Remover</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p><button type="button" class="botao secundario" id="nova-embalagem">Adicionar embalagem</button></p>

        <div class="campo">
            <input type="hidden" name="ativo" value="0">
            <label class="check"><input type="checkbox" name="ativo" value="1" @checked(old('ativo', $sku->ativo ?? true))> Ativo</label>
        </div>

        <button type="submit" class="botao">Salvar</button>
        <a href="{{ route('skus.index') }}" class="botao secundario">Cancelar</a>
    </form>

    <template id="modelo-embalagem">
        <tr>
            <td>
                <select name="embalagens[__i__][tipo]">
                    @foreach ($tipos as $valor => $rotulo)
                        <option value="{{ $valor }}">{{ $rotulo }}</option>
                    @endforeach
                </select>
            </td>
            <td><input type="number" name="embalagens[__i__][quantidade]" min="1" style="width: 100px"></td>
            <td><input type="text" name="embalagens[__i__][gtin]" inputmode="numeric" maxlength="20"></td>
            <td><input type="text" name="embalagens[__i__][codigo]" maxlength="40"></td>
            <td><button type="button" class="link-botao remover-embalagem">Remover</button></td>
        </tr>
    </template>

    @push('scripts')
        <script>
            (function () {
                var corpo = document.querySelector('#embalagens tbody');
                var proximo = {{ count($embalagens) + 100 }};

                document.getElementById('nova-embalagem').addEventListener('click', function () {
                    var html = document.getElementById('modelo-embalagem').innerHTML.replace(/__i__/g, proximo++);
                    corpo.insertAdjacentHTML('beforeend', html);
                });

                corpo.addEventListener('click', function (e) {
                    if (e.target.classList.contains('remover-embalagem')) e.target.closest('tr').remove();
                });
            })();
        </script>
    @endpush
</x-layout>
