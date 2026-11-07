<x-layout titulo="Agências" :marca="tenant('nome')">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>
    <div class="cabecalho">
        <div><h1>Agências</h1><p class="texto-apoio">Agências de promotores que atendem esta instalação. O vínculo é feito pelo fornecedor do sistema; a equipe é cadastrada pela própria agência.</p></div>
    </div>
    <div class="tabela-rolagem">
        <table>
            <thead>
                <tr><th>Nome</th><th>Regiões atendidas</th><th>Situação</th></tr>
            </thead>
            <tbody>
                @forelse ($agencias as $agencia)
                    <tr>
                        <td>{{ $agencia->nome }}@if ($agencia->cnpj)<div class="rotulo">{{ $agencia->cnpj }}</div>@endif</td>
                        <td>{{ $agencia->vinculo?->regioesRotulo() ?? '—' }}</td>
                        <td><span @class(['selo', 'ok' => $agencia->ativo, 'off' => ! $agencia->ativo])>{{ $agencia->ativo ? 'Ativa' : 'Inativa' }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="3">Nenhuma agência disponível para consulta.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
