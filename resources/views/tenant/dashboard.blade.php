<x-layout :titulo="$titulo" :marca="$cliente->nome">
    <x-slot:nav>@include('tenant.nav')</x-slot:nav>
    @if ($precisaAgencia)<div class="alert alert-warning d-flex flex-wrap align-items-center gap-2"><strong>Nenhuma agência vinculada ainda.</strong><span>O vínculo com a agência de promotores é feito pelo fornecedor do sistema. Depois dele, cadastre os contratos de visita.</span></div>@endif
    <div class="painel-cabecalho">
        <div><div class="sobretitulo">{{ $usuario->perfil->label() }} / {{ $cliente->nome }}</div><h1>{{ $titulo }}</h1><p class="texto-apoio">{{ $descricao }}</p></div>
        <form method="GET" action="{{ route('dashboard') }}" class="seletor-dia"><label for="painel-data">Dia da operação</label><div class="acoes"><input id="painel-data" type="date" name="data" value="{{ $data->toDateString() }}" required><button class="botao" type="submit">Consultar</button></div></form>
    </div>
    <div class="welcome-card rounded ps-4 ps-md-5 pt-4 pt-md-5 pb-4 mb-4 position-relative">
        <h4 class="text-warning">Olá, {{ \Illuminate\Support\Str::words($usuario->nome, 1, '') }}.</h4>
        <p class="mb-2">{{ $data->translatedFormat('l, d \d\e F \d\e Y') }}</p>
        <p class="mb-3">{{ $resumo['realizadas'] }} de {{ $resumo['total'] }} visitas do dia realizadas.</p>
        <div class="progress mb-3" style="height:6px; max-width: 360px;" role="progressbar" aria-label="Visitas realizadas no dia" aria-valuenow="{{ $resumo['percentual'] }}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-warning" style="width: {{ $resumo['percentual'] }}%"></div></div>
        <a class="btn btn-warning btn-rounded" href="#visitas-do-dia">{{ $resumo['percentual'] }}% concluído · ver visitas <i class="las la-long-arrow-alt-right ms-2"></i></a>
        <img src="{{ asset('tema/images/svg/welcom-card.svg') }}" alt="" class="position-absolute d-none d-md-block">
    </div>

    <section class="row" aria-label="Resumo das visitas do dia">
        @foreach ([['Visitas no dia', $resumo['total'], 'Total da agenda selecionada', 'primary', 'flaticon-381-calendar-1'], ['Realizadas', $resumo['realizadas'], 'Visitas com status realizado', 'success', 'flaticon-381-success-1'], ['Em andamento', $resumo['emAndamento'], 'Atendimentos iniciados', 'info', 'flaticon-381-stopwatch'], ['Em atenção', $resumo['emAtencao'], 'Visitas que precisam de acompanhamento', 'warning', 'flaticon-381-warning-1']] as [$rotulo, $numero, $ajuda, $cor, $icone])
            <div class="col-xl-3 col-sm-6"><x-indicador :rotulo="$rotulo" :valor="$numero" :ajuda="$ajuda" :cor="$cor" :icone="$icone" /></div>
        @endforeach
    </section>

    <div class="row">
        <div class="col-xl-8">
            <div class="card grafico-card">
                <div class="card-header border-0 pb-0">
                    <div><h4 class="card-title mb-1">Agenda dos últimos 7 dias</h4><p class="rotulo mb-0">Volume de visitas até {{ $data->format('d/m') }}</p></div>
                    <span class="badge badge-primary light">{{ $resumo['semana']->sum('total') }} visitas</span>
                </div>
                <div class="card-body">
                    <x-grafico :config="['tipo' => 'coluna', 'altura' => 290, 'semLegenda' => true, 'categorias' => $resumo['semana']->pluck('dia'), 'series' => [['nome' => 'Visitas', 'dados' => $resumo['semana']->pluck('total')]]]" />
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card grafico-card">
                <div class="card-header border-0 pb-0">
                    <div><h4 class="card-title mb-1">Situação do dia</h4><p class="rotulo mb-0">{{ $data->format('d/m/Y') }} · {{ $resumo['total'] }} visitas</p></div>
                </div>
                <div class="card-body">
                    <x-grafico :config="['tipo' => 'rosca', 'altura' => 250, 'rotuloTotal' => 'Visitas', 'cores' => ['#9aa0a8', '#17a2b8', '#27ae60', '#f5a623', '#dc3545'], 'categorias' => $resumo['estados']->pluck('rotulo'), 'series' => [['nome' => 'Visitas', 'dados' => $resumo['estados']->pluck('total')]]]" />
                    <div class="situacoes mt-3">
                        @foreach ($resumo['estados'] as $estado)<a class="situacao-item" href="{{ route('dashboard', ['data' => $data->toDateString(), 'status' => $estado['valor']]) }}#visitas-do-dia"><span class="ponto status-{{ $estado['valor'] }}"></span><span>{{ $estado['rotulo'] }}</span><strong>{{ $estado['total'] }}</strong></a>@endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <section class="painel-atalhos" aria-label="Ações do seu perfil">
        @include('tenant.painel.atalhos')
    </section>

    @if ($resumo['semPromotor'] || $resumo['pendentesAnteriores'])<section class="painel-pendencias" aria-label="Pontos de atenção"><strong>Pontos de atenção</strong>@if ($resumo['semPromotor'])<span>{{ $resumo['semPromotor'] }} visita(s) do dia sem promotor atribuído.</span>@endif @if ($resumo['pendentesAnteriores'])<span>{{ $resumo['pendentesAnteriores'] }} visita(s) dos 6 dias anteriores continuam previstas.</span>@endif @if ($usuario->temPerfil(\App\Enums\Perfil::AdminAgencia, \App\Enums\Perfil::Supervisor))<a href="{{ route('roteiros.semana', ['semana' => $data->copy()->startOfWeek()->toDateString()]) }}">Abrir roteiros →</a>@endif</section>@endif
    @if ($usuario->perfil === \App\Enums\Perfil::GerentePdv)
        <section id="minhas-lojas" class="secao-painel"><div class="secao-titulo"><div><h2>Lojas e marcas atendidas</h2><p class="rotulo">Contratos vigentes na data selecionada</p></div></div><div class="grade lojas-grade">
            @forelse ($resumo['lojas'] as $loja)<article class="cartao loja-cartao"><span class="sobretitulo">PONTO DE VENDA</span><h3>{{ $loja->nome }}</h3><p class="rotulo">{{ $loja->enderecoCompleto() }}</p>@forelse ($loja->contratos as $contrato)<div class="marca-atendida"><strong>{{ $contrato->industria->nome }}</strong><span>{{ $contrato->agencia->nome }} · {{ $contrato->diasRotulo() }}</span>@if ($contrato->proximaVisita)<p class="rotulo">Próxima visita: {{ $contrato->proximaVisita->data->format('d/m/Y') }} · {{ $contrato->proximaVisita->nomePromotor() ?? 'Aguardando atribuição' }}</p>@else<p class="rotulo">Nenhuma próxima visita atribuída.</p>@endif
@if ($contrato->ultimaVisita)<p><a href="{{ route('visitas.show',$contrato->ultimaVisita) }}">Última visita: {{ $contrato->ultimaVisita->concluida_em?->format('d/m/Y H:i') ?? $contrato->ultimaVisita->data->format('d/m/Y') }} →</a></p><div class="grade fotos-comparacao">@foreach (['antes','depois'] as $tipoFoto)@php($fotoLoja=$contrato->ultimaVisita->fotos->firstWhere('tipo',$tipoFoto))@if($fotoLoja)<figure><figcaption>{{ ucfirst($tipoFoto) }}</figcaption><a href="{{ route('visitas.show',$contrato->ultimaVisita) }}"><img loading="lazy" src="{{ route('visitas.fotos',[$contrato->ultimaVisita,$fotoLoja]) }}" alt="{{ $tipoFoto }} da última visita em {{ $loja->nome }}"></a></figure>@endif @endforeach</div>@else<p class="rotulo">Nenhuma visita realizada registrada.</p>@endif @if ($loja->ativo && $contrato->vigente() && $contrato->agencia->ativo && $contrato->industria->ativo)<div class="acoes"><a href="{{ route('demandas.create', ['contrato_id'=>$contrato->id,'tipo'=>'visita']) }}">Pedir visita</a><a href="{{ route('demandas.create', ['contrato_id'=>$contrato->id,'tipo'=>'ruptura']) }}">Avisar ruptura</a></div>@endif</div>@empty<p class="rotulo">Nenhuma marca com contrato vigente nesta data.</p>@endforelse</article>
            @empty<div class="cartao estado-vazio"><h3>Nenhuma loja vinculada</h3><p>Peça ao administrador para vincular seu usuário aos PDVs que você gerencia.</p></div>@endforelse
        </div>{{ $resumo['lojas']->links('components.paginacao') }}</section>
    @endif
    <section id="visitas-do-dia" class="cartao secao-painel agenda-cartao">
        <div class="secao-titulo"><div><h2>{{ $usuario->perfil === \App\Enums\Perfil::Promotor ? 'Meus atendimentos' : 'Visitas do dia' }}</h2><p class="rotulo">{{ $resumo['visitas']->total() }} atendimento(s) {{ $filtroStatus ? 'com o filtro aplicado' : 'na data selecionada' }}</p></div><form method="GET" action="{{ route('dashboard') }}#visitas-do-dia" class="filtro-situacao"><input type="hidden" name="data" value="{{ $data->toDateString() }}"><label for="painel-status" class="sr-only">Filtrar situação</label><select name="status" id="painel-status"><option value="">Todas as situações</option>@foreach ($resumo['estados'] as $estado)<option value="{{ $estado['valor'] }}" @selected($filtroStatus === $estado['valor'])>{{ $estado['rotulo'] }}</option>@endforeach</select><button type="submit" class="botao secundario">Filtrar</button></form></div>
        <div class="tabela-rolagem"><table><thead><tr><th scope="col">Ponto de venda</th><th scope="col">Indústria / agência</th><th scope="col">Promotor</th><th scope="col">Situação</th><th scope="col">Check-in</th></tr></thead><tbody>
            @forelse ($resumo['visitas'] as $visita)<tr><td><a href="{{ route('visitas.show', $visita) }}"><strong>{{ $visita->pdv->nome }}</strong></a><div class="rotulo endereco-agenda">{{ $visita->pdv->enderecoCompleto() }}</div></td><td>{{ $visita->industria->nome }}<div class="rotulo">{{ $visita->agencia->nome }}</div></td><td>{{ $visita->nomePromotor() ?? 'A atribuir' }}</td><td><span class="selo estado-{{ $visita->status->value }}">{{ $visita->status->label() }}</span></td><td>{{ $visita->check_in ? \Illuminate\Support\Carbon::parse($visita->check_in['gps_em'])->timezone(config('app.timezone'))->format('H:i') : '—' }}</td></tr>
            @empty<tr><td colspan="5"><div class="estado-vazio"><span class="vazio-marca" aria-hidden="true">✓</span><h3>{{ $filtroStatus ? 'Nenhuma visita nesta situação' : 'Nenhuma visita nesta data' }}</h3><p>{{ $usuario->perfil === \App\Enums\Perfil::Promotor ? 'As visitas aparecem aqui quando o supervisor atribui seu roteiro.' : 'Selecione outro dia para consultar a agenda da operação.' }}</p>@if ($filtroStatus)<a href="{{ route('dashboard', ['data' => $data->toDateString()]) }}#visitas-do-dia">Limpar filtro</a>@endif</div></td></tr>@endforelse
        </tbody></table></div>{{ $resumo['visitas']->links('components.paginacao') }}
    </section>
    @unless ($usuario->perfil === \App\Enums\Perfil::Promotor)<section class="secao-painel"><div class="secao-titulo"><div><h2>Base da operação</h2><p class="rotulo">Cadastros dentro do seu acesso · contratos vigentes na data consultada</p></div></div><div class="grade base-operacao">@foreach ($resumo['cadastros'] as $rotulo => $numero)@if ($numero !== null)<div class="cartao"><strong class="numero">{{ $numero }}</strong><span class="rotulo">{{ $rotulo }}</span></div>@endif @endforeach</div></section>@endunless
    @if ($usuario->perfil === \App\Enums\Perfil::GerentePdv)<p class="nota-painel"><a href="{{ route('demandas.create') }}">Pedir visita ou avisar ruptura →</a></p>@endif
    @if ($usuario->temPerfil(\App\Enums\Perfil::GerenteTrade, \App\Enums\Perfil::Representante))<p class="nota-painel">A atribuição de visitas aos promotores é feita pela agência responsável.</p>@endif
</x-layout>
