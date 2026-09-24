@php($editando = $usuario->exists)
<x-layout :titulo="$editando ? 'Editar usuário' : 'Novo usuário'" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>

    <h1>{{ $editando ? 'Editar usuário' : 'Novo usuário' }}</h1>

    <form method="POST" action="{{ $editando ? route('usuarios.update', $usuario) : route('usuarios.store') }}" class="cartao">
        @csrf
        @if ($editando) @method('PUT') @endif

        <div class="linha">
            <div class="campo">
                <label for="nome">Nome</label>
                <input type="text" id="nome" name="nome" value="{{ old('nome', $usuario->nome) }}" required>
            </div>
            <div class="campo">
                <label for="email">E-mail</label>
                <input type="email" id="email" name="email" value="{{ old('email', $usuario->email) }}" required>
            </div>
            <div class="campo">
                <label for="telefone">Telefone</label>
                <input type="text" id="telefone" name="telefone" value="{{ old('telefone', $usuario->telefone) }}">
            </div>
        </div>

        <div class="linha">
            <div class="campo">
                <label for="perfil">Perfil</label>
                <select id="perfil" name="perfil" required>
                    @foreach ($perfis as $valor => $rotulo)
                        <option value="{{ $valor }}" @selected(old('perfil', $usuario->perfil?->value) === $valor)>{{ $rotulo }}</option>
                    @endforeach
                </select>
            </div>

            @if ($eu->perfil === \App\Enums\Perfil::AdminInstalacao)
                <div class="campo">
                    <label for="industria_id">Indústria</label>
                    <select id="industria_id" name="industria_id">
                        <option value="">—</option>
                        @foreach ($industrias as $id => $nome)
                            <option value="{{ $id }}" @selected((string) old('industria_id', $usuario->industria_id) === (string) $id)>{{ $nome }}</option>
                        @endforeach
                    </select>
                    <div class="dica">Obrigatório para gerente de trade e representante.</div>
                </div>
                <div class="campo">
                    <label for="agencia_id">Agência</label>
                    <select id="agencia_id" name="agencia_id">
                        <option value="">—</option>
                        @foreach ($agencias as $id => $nome)
                            <option value="{{ $id }}" @selected((string) old('agencia_id', $usuario->agencia_id) === (string) $id)>{{ $nome }}</option>
                        @endforeach
                    </select>
                    <div class="dica">Obrigatório para admin da agência, supervisor e promotor.</div>
                </div>
            @endif

            <div class="campo">
                <label for="tipo_vinculo">Vínculo do promotor</label>
                <select id="tipo_vinculo" name="tipo_vinculo">
                    <option value="">—</option>
                    @foreach ($vinculos as $vinculo)
                        <option value="{{ $vinculo->value }}" @selected(old('tipo_vinculo', $usuario->tipo_vinculo?->value) === $vinculo->value)>{{ $vinculo->label() }}</option>
                    @endforeach
                </select>
                <div class="dica">Só para promotor.</div>
            </div>
        </div>

        <div class="linha">
            <div class="campo">
                <label for="password">{{ $editando ? 'Nova senha' : 'Senha' }}</label>
                <input type="password" id="password" name="password" autocomplete="new-password" @required(! $editando)>
                <div class="dica">Mínimo de 8 caracteres, com letras e números.{{ $editando ? ' Em branco, mantém a atual.' : '' }}</div>
            </div>
            <div class="campo">
                <label for="password_confirmation">Confirmar senha</label>
                <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password">
            </div>
        </div>

        <div class="campo">
            <input type="hidden" name="ativo" value="0">
            <label class="check"><input type="checkbox" name="ativo" value="1" @checked(old('ativo', $usuario->ativo))> Ativo</label>
        </div>

        <button type="submit" class="botao">Salvar</button>
        <a href="{{ route('usuarios.index') }}" class="botao secundario">Cancelar</a>
    </form>
</x-layout>
