<x-layout titulo="Cadastro de promotor" :marca="tenant('nome')" :visitante="true" :largo="true">
    <style>
        .grade-cadastro { display: grid; grid-template-columns: 1fr; gap: 0 20px; }
        @media (min-width: 768px) { .grade-cadastro { grid-template-columns: 1fr 1fr; } .grade-cadastro .inteira { grid-column: 1 / -1; } }
        .grade-cadastro input[type=text], .grade-cadastro input[type=email], .grade-cadastro input[type=tel], .grade-cadastro input[type=password], .grade-cadastro select, .grade-cadastro input[type=file] { width: 100%; }
    </style>
    <h4 class="text-center mb-2">Cadastro de promotor</h4>
    <p class="text-center mb-4">Preencha os seus dados para usar o aplicativo da <strong>{{ tenant('nome') }}</strong>.</p>

    <form method="POST" action="{{ route('cadastro.enviar', $codigo) }}" enctype="multipart/form-data">
        @csrf
        <div class="grade-cadastro">
        <div class="campo">
            <label for="nome">Nome completo</label>
            <input type="text" id="nome" name="nome" value="{{ old('nome') }}" required maxlength="120" autocomplete="name" autofocus>
        </div>
        <div class="campo">
            <label for="cpf">CPF</label>
            <input type="text" id="cpf" name="cpf" value="{{ old('cpf') }}" required inputmode="numeric" maxlength="14" autocomplete="off" data-mascara="cpf" placeholder="000.000.000-00">
        </div>
        <div class="campo">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required maxlength="190" autocomplete="email">
        </div>
        <div class="campo">
            <label for="telefone">Telefone (WhatsApp)</label>
            <input type="tel" id="telefone" name="telefone" value="{{ old('telefone') }}" required maxlength="15" autocomplete="tel" inputmode="tel" data-mascara="telefone" placeholder="(00) 00000-0000">
        </div>
        <div class="campo">
            <label for="uf_base">UF</label>
            <select id="uf_base" name="uf_base" required data-cidade="municipio_ibge" data-url="{{ route('municipios.uf', '__UF__') }}">
                <option value="">Escolha</option>
                @foreach ($ufs as $sigla => $nome)
                    <option value="{{ $sigla }}" @selected(old('uf_base') === $sigla)>{{ $nome }}</option>
                @endforeach
            </select>
        </div>
        <div class="campo">
            <label for="municipio_ibge">Cidade onde você atua</label>
            <select id="municipio_ibge" name="municipio_ibge" required data-atual="{{ old('municipio_ibge') }}">
                <option value="">Escolha a UF primeiro</option>
            </select>
        </div>
        <div class="campo">
            <label for="raio_km">Até quantos km da cidade você aceita trabalhar</label>
            <input type="number" id="raio_km" name="raio_km" value="{{ old('raio_km', 30) }}" min="5" max="200" step="1">
            <div class="dica">Entre 5 e 200 km. Você pode mudar depois com a sua agência.</div>
        </div>
        <div class="campo inteira">
            <label for="foto">Foto do rosto para o crachá digital (opcional)</label>
            <input type="file" id="foto" name="foto" accept="image/jpeg,image/png,image/webp">
        </div>
        <div class="campo">
            <label for="password">Crie uma senha</label>
            <input type="password" id="password" name="password" required autocomplete="new-password" data-ver-senha>
            <div class="dica">Mínimo de 8 caracteres, com letras e números.</div>
        </div>
        <div class="campo">
            <label for="password_confirmation">Repita a senha</label>
            <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password" data-ver-senha>
        </div>

        </div>

        <div class="cartao" style="font-size:13px;line-height:1.5;max-height:240px;overflow:auto;margin:16px 0" tabindex="0" aria-label="Termos de uso compartilhado da rede da plataforma">
            <strong>Termos de uso compartilhado da rede da plataforma</strong>
            @foreach (preg_split('/\R{2,}/', $termo) as $paragrafo)
                <p style="margin:8px 0 0">{{ $paragrafo }}</p>
            @endforeach
        </div>
        <div class="campo">
            <label class="check"><input type="checkbox" name="aceite" value="1" required @checked(old('aceite'))> Aceito os termos de uso compartilhado da rede da plataforma</label>
        </div>
        <button type="submit" class="botao" style="width:100%">Criar meu cadastro</button>
    </form>
    <script src="{{ asset('js/municipios.js') }}" defer></script>
    <script src="{{ asset('js/formulario.js') }}" defer></script>
</x-layout>
