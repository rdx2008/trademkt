<x-layout titulo="Agências" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>
    <div class="cabecalho">
        <div><h1>Agências</h1><p class="texto-apoio">Agências de promotores que atendem esta instalação. A equipe é cadastrada pela própria agência.</p></div>
        @if ($podeCriar)
            <a class="botao" href="{{ route('agencias.create') }}">Nova agência</a>
        @endif
    </div>

    @if (session('convite_agencia'))
        <div class="cartao" id="cartao-convite">
            <h2>Link de primeiro acesso</h2>
            <p class="dica">Envie este link para {{ session('convite_agencia')['nome'] }}. Ele cria a senha e entra no painel da agência. Vale por 7 dias, só pode ser usado uma vez e não aparece de novo.</p>
            <div class="campo"><input type="text" value="{{ session('convite_agencia')['url'] }}" readonly onfocus="this.select()"></div>
            <a class="botao secundario" target="_blank" rel="noopener noreferrer" href="https://wa.me/?text={{ rawurlencode('Olá! Acesse o link para criar sua senha do painel da agência: '.session('convite_agencia')['url']) }}">Enviar por WhatsApp</a>
        </div>
    @endif

    <div class="tabela-rolagem">
        <table>
            <thead>
                <tr><th>Nome</th><th>Tipo</th><th>Regiões atendidas</th><th>Situação</th></tr>
            </thead>
            <tbody>
                @forelse ($agencias as $agencia)
                    <tr>
                        <td>{{ $agencia->nome }}@if ($agencia->empresa?->cnpj)<div class="rotulo">{{ $agencia->empresa->cnpjFormatado() }}</div>@elseif ($agencia->cnpj)<div class="rotulo">{{ $agencia->cnpj }}</div>@endif</td>
                        <td>{{ $agencia->empresa?->modo === \App\Enums\ModoEmpresa::Interna ? 'Interna' : 'Externa' }}</td>
                        <td>{{ $agencia->vinculo?->regioesRotulo() ?? '—' }}</td>
                        <td><span @class(['selo', 'ok' => $agencia->ativo, 'off' => ! $agencia->ativo])>{{ $agencia->ativo ? 'Ativa' : 'Inativa' }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="4">Nenhuma agência disponível para consulta.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
