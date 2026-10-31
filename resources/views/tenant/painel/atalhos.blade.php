@php
    $semana = ['semana' => $data->copy()->startOfWeek()->toDateString()];
    $atalhos = match ($usuario->perfil) {
        \App\Enums\Perfil::AdminInstalacao => [
            ['Gerenciar a equipe', 'Cadastre usuários e defina seus acessos', route('usuarios.index')],
            ['Cadastrar um PDV', 'Endereço, localização e raio de check-in', route('pdvs.create')],
            ['Contratos e atendimento', 'Consulte frequências e prestadoras', route('contratos.index')],
        ],
        \App\Enums\Perfil::AdminAgencia => [
            ['Minha equipe', 'Promotores e supervisores da agência', route('usuarios.index')],
            ['Planejar a semana', 'Distribua visitas entre os promotores', route('roteiros.semana', $semana)],
            ['Novo contrato', 'Defina os dias de atendimento do PDV', route('contratos.create')],
        ],
        \App\Enums\Perfil::Supervisor => [
            ['Distribuir visitas', 'Ajuste o roteiro e os responsáveis', route('roteiros.semana', $semana)],
            ['Consultar PDVs', 'Confira endereços e localização', route('pdvs.index')],
        ],
        \App\Enums\Perfil::GerenteTrade => [
            ['Agências prestadoras', 'Consulte as responsáveis pela sua marca', route('agencias.index')],
            ['Catálogo da indústria', 'Organize produtos e embalagens', route('skus.index')],
            ['Representantes e equipe', 'Consulte os acessos da sua operação', route('usuarios.index')],
        ],
        \App\Enums\Perfil::Representante => [
            ['Consultar PDVs', 'Consulte os pontos de venda da indústria', route('pdvs.index')],
            ['Produtos da marca', 'Consulte SKUs e embalagens', route('skus.index')],
        ],
        \App\Enums\Perfil::Promotor => [['Meu roteiro do dia', 'Lojas, endereços e status dos atendimentos', '#visitas-do-dia']],
        \App\Enums\Perfil::GerentePdv => [
            ['Marcas nas minhas lojas', 'Consulte agências e dias de atendimento', '#minhas-lojas'],
            ['Dados das lojas', 'Endereços dos PDVs vinculados a você', route('pdvs.index')],
        ],
    };
@endphp
@foreach ($atalhos as [$nome, $ajuda, $url])
    <a class="atalho" href="{{ $url }}"><strong>{{ $nome }} <span aria-hidden="true">↗</span></strong><span>{{ $ajuda }}</span></a>
@endforeach
@if ($usuario->perfil === \App\Enums\Perfil::Promotor)
    <div class="atalho informativo"><strong>Execução pelo aplicativo</strong><span>O app Android, as fotos e a conclusão da visita estão em desenvolvimento.</span></div>
@endif
