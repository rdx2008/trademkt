<x-layout titulo="Meus documentos" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>
    <div class="cabecalho">
        <div>
            <h1>Meus documentos</h1>
            <p class="texto-apoio">Contratos, planogramas, comunicados e treinamentos da empresa. Você vê só o que o seu perfil pode ver.</p>
        </div>
    </div>

    @if ($podeGerir)
        <form method="POST" action="{{ route('documentos.store') }}" enctype="multipart/form-data" class="cartao">
            @csrf
            <h2>Enviar documento</h2>
            <div class="linha">
                <div class="campo"><label for="titulo">Título</label><input id="titulo" name="titulo" value="{{ old('titulo') }}" maxlength="150" required>@error('titulo')<div class="erro">{{ $message }}</div>@enderror</div>
                <div class="campo"><label for="categoria">Categoria</label><select id="categoria" name="categoria">@foreach ($categorias as $v => $r)<option value="{{ $v }}">{{ $r }}</option>@endforeach</select></div>
            </div>
            <div class="campo"><label for="descricao">Descrição (opcional)</label><input id="descricao" name="descricao" value="{{ old('descricao') }}" maxlength="500"></div>
            <div class="campo">
                <label for="arquivo">Arquivo (até 20 MB)</label>
                <input id="arquivo" type="file" name="arquivo" required>
                @error('arquivo')<div class="erro">{{ $message }}</div>@enderror
                <div class="dica">PDF, Word, Excel, PowerPoint, CSV, texto, imagem ou ZIP.</div>
            </div>
            <div class="campo">
                <label>Quem pode ver</label>
                <div class="dica">Sem nenhum marcado: admin da agência, supervisor, gerente de trade e representante. O promotor e o gerente de supermercado só veem se você marcar. O admin da instalação sempre vê.</div>
                @foreach ($perfis as $v => $r)<label class="check"><input type="checkbox" name="perfis[]" value="{{ $v }}"> {{ $r }}</label>@endforeach
            </div>
            <button type="submit" class="botao">Enviar</button>
        </form>
    @endif

    <div class="tabela-rolagem">
        <table>
            <thead><tr><th>Documento</th><th>Categoria</th><th>Tamanho</th><th>Enviado em</th>@if ($podeGerir)<th>Quem vê</th>@endif<th></th></tr></thead>
            <tbody>
                @forelse ($documentos as $d)
                    <tr>
                        <td>{{ $d->titulo }}@if ($d->descricao)<div class="rotulo">{{ $d->descricao }}</div>@endif<div class="rotulo">{{ $d->nome_original }}</div></td>
                        <td>{{ $d->rotuloCategoria() }}</td>
                        <td>{{ number_format($d->tamanho / 1024, 0, ',', '.') }} KB</td>
                        <td style="white-space:nowrap">{{ $d->created_at->format('d/m/Y') }}</td>
                        @if ($podeGerir)<td>{{ $d->perfis ? collect($d->perfis)->map(fn ($p) => $perfis[$p] ?? $p)->implode(', ') : 'Padrão' }}</td>@endif
                        <td style="white-space:nowrap">
                            <a href="{{ route('documentos.baixar', $d->id) }}">Baixar</a>
                            @if ($podeGerir)
                                <form method="POST" action="{{ route('documentos.destroy', $d->id) }}" style="display:inline">@csrf @method('DELETE')<button type="submit" class="link-botao" onclick="return confirm('Remover este documento?')">Remover</button></form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">Nenhum documento disponível.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
