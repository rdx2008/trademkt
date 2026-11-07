@php($editando = $usuario->exists)
<x-layout :titulo="$editando ? $usuario->nome : 'Novo promotor'" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>
    <div class="cabecalho">
        <h1>{{ $editando ? 'Promotor: '.$usuario->nome : 'Novo promotor' }}</h1>
        <a class="botao secundario" href="{{ route('promotores.index') }}">Voltar à lista</a>
    </div>

    @if (session('convite_url'))
        <div class="cartao" id="cartao-convite">
            <h2>Convite de primeiro acesso</h2>
            <p class="dica">Envie este link ao promotor (por WhatsApp, por exemplo). Ele cria a própria senha e confirma o aviso de privacidade. O link vale por {{ \App\Services\Promotores\Cadastro::VALIDADE_CONVITE_DIAS }} dias, só pode ser usado uma vez e não aparece de novo: se perder, gere outro.</p>
            <div class="campo">
                <input type="text" id="link-convite" value="{{ session('convite_url') }}" readonly onfocus="this.select()">
            </div>
            <div class="acoes">
                <button type="button" class="botao" id="copiar-convite">Copiar link</button>
                <a class="botao secundario" target="_blank" rel="noopener noreferrer" href="https://wa.me/?text={{ rawurlencode('Olá! Acesse o link para criar sua senha do aplicativo '.tenant('nome').': '.session('convite_url')) }}">Enviar por WhatsApp</a>
            </div>
            <p class="dica">Depois de criar a senha, no aplicativo ele informa o código da empresa <strong>{{ tenant('codigo') }}</strong> e entra com o e-mail e a senha dele.</p>
        </div>
    @endif

    <form method="POST" enctype="multipart/form-data" action="{{ $editando ? route('promotores.update', $usuario->id) : route('promotores.store') }}" class="cartao">
        @csrf
        @if ($editando) @method('PUT') @endif

        <div class="linha">
            <div class="campo">
                <label for="nome">Nome completo</label>
                <input type="text" id="nome" name="nome" value="{{ old('nome', $usuario->nome) }}" required maxlength="120">
            </div>
            <div class="campo">
                <label for="email">E-mail (é o login no aplicativo)</label>
                <input type="email" id="email" name="email" value="{{ old('email', $usuario->email) }}" required maxlength="190">
            </div>
            <div class="campo">
                <label for="telefone">Telefone / WhatsApp</label>
                <input type="text" id="telefone" name="telefone" value="{{ old('telefone', $usuario->telefone) }}" maxlength="20">
            </div>
        </div>

        <div class="linha">
            <div class="campo">
                <label for="cpf">CPF</label>
                <input type="text" id="cpf" name="cpf" value="{{ old('cpf', $cpfAtual) }}" inputmode="numeric" maxlength="14" placeholder="000.000.000-00" autocomplete="off" @required(! $editando || ! $usuario->cpf)>
                <div class="dica">Guardado criptografado e visível só para a gestão da agência. A indústria e os mercados nunca veem o CPF.</div>
            </div>
            <div class="campo">
                <label for="tipo_vinculo">Vínculo</label>
                <select id="tipo_vinculo" name="tipo_vinculo" required>
                    @foreach ($vinculos as $vinculo)
                        <option value="{{ $vinculo->value }}" @selected(old('tipo_vinculo', $usuario->tipo_vinculo?->value) === $vinculo->value)>{{ $vinculo->label() }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="linha">
            <div class="campo">
                <label for="cidade_base">Cidade base</label>
                <input type="text" id="cidade_base" name="cidade_base" value="{{ old('cidade_base', $usuario->cidade_base) }}" maxlength="80">
                <div class="dica">Onde a pessoa mora ou atende. Ajuda a distribuir as visitas.</div>
            </div>
            <div class="campo">
                <label for="uf_base">UF</label>
                <select id="uf_base" name="uf_base">
                    <option value="">—</option>
                    @foreach ($ufs as $sigla => $nome)
                        <option value="{{ $sigla }}" @selected(old('uf_base', $usuario->uf_base) === $sigla)>{{ $sigla }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="linha">
            <div class="campo">
                <label for="foto">Foto do rosto</label>
                @if ($usuario->foto)
                    <img src="{{ route('promotores.foto', $usuario->id) }}" alt="Foto de {{ $usuario->nome }}" style="width:96px;height:96px;border-radius:12px;object-fit:cover;display:block;margin-bottom:8px">
                @endif
                <input type="file" id="foto" name="foto" accept="image/jpeg,image/png,image/webp">
                <div class="dica">JPG, PNG ou WebP até 3 MB. Aparece no crachá digital, para a loja conferir quem está lá. É recortada em quadrado.</div>
            </div>
            <div class="campo">
                <label for="observacoes">Observações internas</label>
                <textarea id="observacoes" name="observacoes" rows="4" maxlength="2000">{{ old('observacoes', $usuario->observacoes) }}</textarea>
                <div class="dica">Só a agência vê.</div>
            </div>
        </div>

        <button type="submit" class="botao">{{ $editando ? 'Salvar' : 'Cadastrar e gerar convite' }}</button>
        <a href="{{ route('promotores.index') }}" class="botao secundario">Cancelar</a>
    </form>

    @if ($editando)
        <div class="cartao">
            <h2>Acesso ao aplicativo</h2>
            <p>
                @if (! $usuario->ativo)
                    <span class="selo off">Inativo</span> O aplicativo dele está desconectado e ele não recebe visitas.
                @elseif (! $usuario->conviteAceito())
                    <span class="selo alerta">Convite pendente</span> Ainda não criou a senha. Último link gerado: {{ $usuario->convites()->latest('id')->first()?->created_at?->format('d/m/Y H:i') ?? '—' }}.
                @else
                    <span class="selo ok">Ativo</span> Senha criada em {{ $usuario->aceite_privacidade_em->format('d/m/Y H:i') }}. Último acesso: {{ $usuario->ultimo_acesso_em?->format('d/m/Y H:i') ?? 'nunca' }}.
                @endif
            </p>
            <div class="acoes">
                @if ($usuario->ativo)
                    <form method="POST" action="{{ route('promotores.convite', $usuario->id) }}">
                        @csrf
                        <button type="submit" class="botao secundario" onclick="return confirm('Gerar um novo link? Os anteriores deixam de valer e a senha atual continua até ele usar o novo link.')">{{ $usuario->conviteAceito() ? 'Redefinir senha (novo link)' : 'Gerar novo convite' }}</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('promotores.ativo', $usuario->id) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="ativo" value="{{ $usuario->ativo ? 0 : 1 }}">
                    <button type="submit" class="botao secundario" onclick="return confirm('{{ $usuario->ativo ? 'Desativar este promotor? O aplicativo dele será desconectado.' : 'Reativar este promotor?' }}')">{{ $usuario->ativo ? 'Desativar' : 'Reativar' }}</button>
                </form>
            </div>
        </div>

        <div class="cartao">
            <h2>Histórico do cadastro</h2>
            <p class="dica">Quem alterou o quê e quando. O CPF nunca aparece aqui, só o fato de ter sido alterado ou consultado.</p>
            <div class="tabela-rolagem">
                <table>
                    <thead><tr><th>Quando</th><th>O quê</th><th>Quem</th></tr></thead>
                    <tbody>
                        @forelse ($historico as $h)
                            <tr>
                                <td>{{ $h->created_at->format('d/m/Y H:i') }}</td>
                                <td>{{ $h->rotulo() }}@if (! empty($h->detalhes['campos'])) <span class="rotulo">({{ implode(', ', $h->detalhes['campos']) }})</span>@endif</td>
                                <td>{{ $h->autor_id ? ($autores[$h->autor_id] ?? 'Usuário removido') : 'O próprio promotor' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3">Sem registros.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @push('scripts')
        <script>
            (function () {
                var cpf = document.getElementById('cpf');
                cpf.addEventListener('input', function () {
                    var d = cpf.value.replace(/\D/g, '').slice(0, 11);
                    cpf.value = d.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
                });
                var botao = document.getElementById('copiar-convite');
                if (botao) {
                    botao.addEventListener('click', function () {
                        var campo = document.getElementById('link-convite');
                        campo.select();
                        (navigator.clipboard ? navigator.clipboard.writeText(campo.value) : Promise.resolve(document.execCommand('copy')))
                            .then(function () { botao.textContent = 'Copiado!'; });
                    });
                }
            })();
        </script>
    @endpush
</x-layout>
