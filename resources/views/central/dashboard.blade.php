<x-layout titulo="Visão geral da plataforma" marca="Trade Promo">
    <x-slot:nav>@include('central.nav')</x-slot:nav>
    <div class="painel-cabecalho"><div><div class="sobretitulo">SUPER ADMIN / PLATAFORMA</div><h1>Visão geral da plataforma</h1><p class="texto-apoio">Gerencie suas instalações e acompanhe o acesso dos clientes.</p></div><a class="botao" href="{{ route('central.clientes.create') }}">+ Novo cliente</a></div>
    <div class="painel-boas-vindas"><div><span class="sobretitulo">CONTROLE CENTRAL</span><h2>Olá, {{ auth('admin')->user()->nome }}.</h2><p>Cada cliente tem seu próprio domínio, banco de dados e equipe.</p></div><div class="progresso-resumo"><strong>{{ $ativos }}</strong><span>instalações ativas</span><span>{{ $total - $ativos }} suspensa(s)</span></div></div>
    <section class="grade indicadores" aria-label="Resumo da plataforma">
        @foreach ([['Clientes cadastrados', $total, 'Total de instalações', 'neutro'], ['Indústrias', $industrias, 'Instalações de indústria', 'azul'], ['Agências', $agencias, 'Instalações de agência', 'verde'], ['Domínios cadastrados', $dominios, 'Endereços vinculados aos clientes', 'amarelo']] as [$rotulo, $numero, $ajuda, $cor])
            <article class="cartao indicador {{ $cor }}"><span class="rotulo">{{ $rotulo }}</span><strong class="numero">{{ $numero }}</strong><span class="indicador-ajuda">{{ $ajuda }}</span></article>
        @endforeach
    </section>
    <section class="painel-atalhos"><a class="atalho" href="{{ route('central.clientes.create') }}"><strong>Criar uma instalação <span aria-hidden="true">↗</span></strong><span>Configure o cliente e seu administrador</span></a><a class="atalho" href="{{ route('central.clientes.index') }}"><strong>Gerenciar clientes <span aria-hidden="true">↗</span></strong><span>Consulte domínios, suspenda ou reative acessos</span></a></section>
    @if ($semDominio)<div class="painel-pendencias"><strong>Atenção à configuração</strong><span>{{ $semDominio }} instalação(ões) sem domínio cadastrado.</span></div>@endif
    <section class="cartao agenda-cartao"><div class="secao-titulo"><div><h2>Instalações recentes</h2><p class="rotulo">Últimos clientes cadastrados na plataforma</p></div><a href="{{ route('central.clientes.index') }}">Ver todos →</a></div><div class="tabela-rolagem"><table><thead><tr><th scope="col">Cliente</th><th scope="col">Tipo</th><th scope="col">Domínio</th><th scope="col">Situação</th></tr></thead><tbody>
        @forelse ($recentes as $cliente)
            <tr><td><strong>{{ $cliente->nome }}</strong><div class="rotulo">Código: {{ $cliente->codigo }}</div></td><td>{{ $cliente->tipo->label() }}</td><td>@forelse ($cliente->domains as $dominio)<div><a href="{{ config('trade.esquema_url') }}://{{ $dominio->domain }}" target="_blank" rel="noopener">{{ $dominio->domain }} ↗</a></div>@empty<span class="rotulo">Sem domínio</span>@endforelse</td><td><span @class(['selo', 'ok' => $cliente->ativo, 'off' => ! $cliente->ativo])>{{ $cliente->ativo ? 'Ativo' : 'Suspenso' }}</span></td></tr>
        @empty
            <tr><td colspan="4"><div class="estado-vazio"><h3>Sua plataforma está pronta para o primeiro cliente</h3><p>Cadastre uma indústria ou agência para começar.</p><a class="botao" href="{{ route('central.clientes.create') }}">Criar primeiro cliente</a></div></td></tr>
        @endforelse
    </tbody></table></div></section>
</x-layout>
