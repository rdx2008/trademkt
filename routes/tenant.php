<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController as ApiAuthController;
use App\Http\Controllers\Api\CheckInController;
use App\Http\Controllers\Api\ExecucaoVisitaController;
use App\Http\Controllers\Api\RoteiroController as ApiRoteiroController;
use App\Http\Controllers\Tenant\AgenciaController;
use App\Http\Controllers\Tenant\AlertaController;
use App\Http\Controllers\Tenant\AparenciaController;
use App\Http\Controllers\Tenant\AuthController;
use App\Http\Controllers\Tenant\BiController;
use App\Http\Controllers\Tenant\ContratoVisitaController;
use App\Http\Controllers\Tenant\ConviteController;
use App\Http\Controllers\Tenant\CrachaController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\DemandaController;
use App\Http\Controllers\Tenant\FichaPromotorController;
use App\Http\Controllers\Tenant\ImportacaoPdvController;
use App\Http\Controllers\Tenant\ImportacaoSkuController;
use App\Http\Controllers\Tenant\IndicadorController;
use App\Http\Controllers\Tenant\IndustriaController;
use App\Http\Controllers\Tenant\MarcaController;
use App\Http\Controllers\Tenant\OperacaoController;
use App\Http\Controllers\Tenant\PdvController;
use App\Http\Controllers\Tenant\PromotorController;
use App\Http\Controllers\Tenant\RedeController;
use App\Http\Controllers\Tenant\RegiaoController;
use App\Http\Controllers\Tenant\RoteiroController;
use App\Http\Controllers\Tenant\SkuController;
use App\Http\Controllers\Tenant\UsuarioController;
use App\Http\Controllers\Tenant\VisitaController;
use App\Http\Middleware\AbrirOperacaoDaAgencia;
use App\Http\Middleware\EnsureInstalacaoDaSessao;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
 * Rotas de cada cliente. O cliente é identificado pelo domínio acessado.
 */

// Painel web
Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
    'cliente.ativo',
])->group(function () {
    Route::redirect('/', '/painel');

    // Marca da instalação (folha de estilo e logos): públicos, a tela de entrada precisa deles.
    Route::get('/marca/tema.css', [MarcaController::class, 'css'])->name('marca.css');
    Route::get('/marca/{tipo}', [MarcaController::class, 'arquivo'])->whereIn('tipo', ['claro', 'escuro', 'icone'])->name('marca.arquivo');

    // Primeiro acesso do promotor: o token do convite é a credencial.
    Route::get('/convite/{token}', [ConviteController::class, 'mostrar'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1')->name('convite.mostrar');
    Route::post('/convite/{token}', [ConviteController::class, 'aceitar'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1')->name('convite.aceitar');
    Route::get('/convite', [ConviteController::class, 'pronto'])->name('convite.pronto');
    // Conferência do crachá: quem lê o QR vê foto, primeiro nome e se o cadastro está ativo.
    Route::get('/verificar/{codigo}', [CrachaController::class, 'verificar'])->where('codigo', '[A-Za-z0-9]{40}')->middleware('throttle:60,1')->name('cracha.verificar');
    Route::get('/verificar/{codigo}/foto', [CrachaController::class, 'foto'])->where('codigo', '[A-Za-z0-9]{40}')->middleware('throttle:60,1')->name('cracha.foto');

    Route::middleware('guest')->group(function () {
        Route::get('/entrar', [AuthController::class, 'create'])->name('login');
        Route::post('/entrar', [AuthController::class, 'store'])->middleware('throttle:login');
    });

    Route::middleware(['auth', EnsureInstalacaoDaSessao::class, AbrirOperacaoDaAgencia::class])->group(function () {
        // Agência: escolhe em qual indústria vinculada está trabalhando.
        Route::post('/operacao/{industria}', [OperacaoController::class, 'selecionar'])->name('operacao.selecionar');

        Route::middleware('perfil:admin_instalacao')->group(function () {
            Route::get('/configuracoes/aparencia', [AparenciaController::class, 'edit'])->name('configuracoes.aparencia');
            Route::put('/configuracoes/aparencia', [AparenciaController::class, 'update'])->name('configuracoes.aparencia.salvar');
            Route::delete('/configuracoes/aparencia', [AparenciaController::class, 'destroy'])->name('configuracoes.aparencia.restaurar');
        });

        Route::get('/demandas', [DemandaController::class, 'index'])->name('demandas.index');
        Route::get('/demandas/nova', [DemandaController::class, 'create'])->name('demandas.create');
        Route::post('/demandas', [DemandaController::class, 'store'])->name('demandas.store');
        Route::get('/demandas/{demanda}', [DemandaController::class, 'show'])->whereNumber('demanda')->name('demandas.show');
        Route::patch('/demandas/{demanda}', [DemandaController::class, 'decidir'])->whereNumber('demanda')->name('demandas.decidir');
        Route::get('/demandas/{demanda}/foto', [DemandaController::class, 'foto'])->whereNumber('demanda')->name('demandas.foto');
        Route::get('/alertas', [AlertaController::class, 'index'])->name('alertas.index');
        Route::patch('/alertas/{alerta}/lida', [AlertaController::class, 'ler'])->whereNumber('alerta')->name('alertas.ler');
        Route::get('/indicadores', [IndicadorController::class, 'index'])->name('indicadores.index');
        Route::get('/indicadores/galeria', [IndicadorController::class, 'galeria'])->name('indicadores.galeria');
        Route::get('/indicadores/excel', [IndicadorController::class, 'exportar'])->name('indicadores.exportar');
        Route::get('/bi', [BiController::class, 'index'])->middleware('perfil:admin_instalacao,admin_agencia,supervisor,gerente_trade')->name('bi.index');
        Route::get('/visitas/{visita}', [VisitaController::class, 'show'])->whereNumber('visita')->name('visitas.show');
        Route::get('/visitas/{visita}/fotos/{foto}', [VisitaController::class, 'foto'])->whereNumber(['visita', 'foto'])->name('visitas.fotos');
        Route::post('/sair', [AuthController::class, 'destroy'])->name('logout');
        Route::get('/painel', DashboardController::class)->name('dashboard');

        // Promotores da agência: supervisor consulta, admin cadastra (conferido no controller).
        Route::middleware('perfil:admin_instalacao,admin_agencia,supervisor')->group(function () {
            Route::get('/promotores', [PromotorController::class, 'index'])->name('promotores.index');
            Route::get('/promotores/novo', [PromotorController::class, 'create'])->name('promotores.create');
            Route::post('/promotores', [PromotorController::class, 'store'])->name('promotores.store');
            Route::get('/promotores/importar', [PromotorController::class, 'importar'])->name('promotores.importar');
            Route::post('/promotores/importar', [PromotorController::class, 'importarEnviar'])->name('promotores.importar.enviar');
            Route::get('/promotores/importar/resultado', [PromotorController::class, 'importarResultado'])->name('promotores.importar.resultado');
            Route::get('/promotores/importar/links', [PromotorController::class, 'importarLinks'])->name('promotores.importar.links');
            Route::get('/promotores/importar/modelo', [PromotorController::class, 'modelo'])->name('promotores.importar.modelo');
            Route::get('/promotores/{promotor}/editar', [PromotorController::class, 'edit'])->whereNumber('promotor')->name('promotores.edit');
            Route::put('/promotores/{promotor}', [PromotorController::class, 'update'])->whereNumber('promotor')->name('promotores.update');
            Route::patch('/promotores/{promotor}/ativo', [PromotorController::class, 'ativo'])->whereNumber('promotor')->name('promotores.ativo');
            Route::post('/promotores/{promotor}/convite', [PromotorController::class, 'convite'])->whereNumber('promotor')->name('promotores.convite');
            Route::get('/promotores/{promotor}', [FichaPromotorController::class, 'show'])->whereNumber('promotor')->name('promotores.ficha');
            Route::get('/promotores/{promotor}/redistribuir', [FichaPromotorController::class, 'redistribuir'])->whereNumber('promotor')->name('promotores.redistribuir');
            Route::post('/promotores/{promotor}/redistribuir', [FichaPromotorController::class, 'aplicarRedistribuicao'])->whereNumber('promotor')->name('promotores.redistribuir.aplicar');
            Route::get('/promotores/{promotor}/cracha', [CrachaController::class, 'mostrar'])->whereNumber('promotor')->name('promotores.cracha');
            Route::put('/promotores/{promotor}/industrias', [FichaPromotorController::class, 'habilitar'])->whereNumber('promotor')->name('promotores.industrias');
            Route::post('/promotores/{promotor}/afastamentos', [FichaPromotorController::class, 'afastar'])->whereNumber('promotor')->name('promotores.afastar');
            Route::delete('/promotores/{promotor}/afastamentos/{afastamento}', [FichaPromotorController::class, 'encerrarAfastamento'])->whereNumber(['promotor', 'afastamento'])->name('promotores.afastamento.remover');
            Route::get('/promotores/{promotor}/skus.csv', [FichaPromotorController::class, 'skusCsv'])->whereNumber('promotor')->name('promotores.skus.csv');
            Route::put('/promotores/{promotor}/ocorrencias/{ocorrencia}', [FichaPromotorController::class, 'editarOcorrencia'])->whereNumber(['promotor', 'ocorrencia'])->name('promotores.ocorrencia.editar');
            Route::delete('/promotores/{promotor}/ocorrencias/{ocorrencia}', [FichaPromotorController::class, 'removerOcorrencia'])->whereNumber(['promotor', 'ocorrencia'])->name('promotores.ocorrencia.remover');
            Route::post('/promotores/{promotor}/ocorrencias', [FichaPromotorController::class, 'ocorrencia'])->whereNumber('promotor')->name('promotores.ocorrencia');
            Route::delete('/promotores/{promotor}/aparelhos/{token}', [FichaPromotorController::class, 'revogarAparelho'])->whereNumber(['promotor', 'token'])->name('promotores.aparelho.revogar');
            Route::get('/promotores/{promotor}/foto', [PromotorController::class, 'foto'])->whereNumber('promotor')->name('promotores.foto');
        });

        Route::middleware('perfil:admin_instalacao,admin_agencia,gerente_trade')->group(function () {
            Route::resource('usuarios', UsuarioController::class)
                ->except(['show', 'destroy'])
                ->parameters(['usuarios' => 'usuario']);
        });

        // PDVs: consulta para quem acompanha (cada perfil vê só o que lhe cabe)
        Route::get('/pdvs', [PdvController::class, 'index'])
            ->middleware('perfil:admin_instalacao,admin_agencia,supervisor,gerente_trade,representante,gerente_pdv')
            ->name('pdvs.index');

        // Catálogo de SKUs: consulta para admin, agência e indústria; cadastro para o admin da
        // instalação e o gerente de trade (só a própria marca), conferido no controller.
        // Importação em lote (planilha ou PDF): admin da instalação e gerente de trade da marca.
        // Declarada antes do resource para que "importar" não seja lido como um SKU.
        Route::middleware('perfil:admin_instalacao,gerente_trade')->group(function () {
            Route::get('/skus/importar', [ImportacaoSkuController::class, 'create'])->name('skus.importar');
            Route::get('/skus/importar/modelo', [ImportacaoSkuController::class, 'modelo'])->name('skus.importar.modelo');
            Route::post('/skus/importar', [ImportacaoSkuController::class, 'store'])->name('skus.importar.enviar');
            Route::get('/skus/importar/{importacao}', [ImportacaoSkuController::class, 'show'])->name('skus.importar.revisar');
            Route::post('/skus/importar/{importacao}', [ImportacaoSkuController::class, 'update'])->name('skus.importar.gravar');
            Route::delete('/skus/importar/{importacao}', [ImportacaoSkuController::class, 'destroy'])->name('skus.importar.descartar');
        });

        Route::middleware('perfil:admin_instalacao,admin_agencia,supervisor,gerente_trade,representante')->group(function () {
            Route::resource('skus', SkuController::class)
                ->except(['show', 'destroy'])
                ->parameters(['skus' => 'sku']);
            Route::get('/skus/{sku}/foto', [SkuController::class, 'foto'])->name('skus.foto');
        });

        // Contratos de visita: consulta para agência e indústria (cada uma vê os seus);
        // cadastro pelo admin da instalação e pelo admin da agência.
        Route::get('/contratos', [ContratoVisitaController::class, 'index'])
            ->middleware('perfil:admin_instalacao,admin_agencia,supervisor,gerente_trade,representante')
            ->name('contratos.index');
        Route::middleware('perfil:admin_instalacao,admin_agencia')->group(function () {
            Route::resource('contratos', ContratoVisitaController::class)
                ->only(['create', 'store', 'edit', 'update'])
                ->parameters(['contratos' => 'contrato']);
        });

        // Roteiros: agência e indústria acompanham; só supervisor e admin da agência atribuem
        // (regra trabalhista, conferida no controller).
        Route::get('/roteiros', [RoteiroController::class, 'semana'])
            ->middleware('perfil:admin_instalacao,admin_agencia,supervisor,gerente_trade,representante')
            ->name('roteiros.semana');
        Route::middleware('perfil:admin_agencia,supervisor')->group(function () {
            Route::post('/roteiros/gerar', [RoteiroController::class, 'gerar'])->name('roteiros.gerar');
            Route::put('/visitas/{visita}/mover', [RoteiroController::class, 'mover'])->name('visitas.mover');
        });

        // Cadastro de PDVs, regiões e redes
        Route::middleware('perfil:admin_instalacao,admin_agencia')->group(function () {
            Route::get('/pdvs/importar', [ImportacaoPdvController::class, 'create'])->name('pdvs.importar');
            Route::post('/pdvs/importar', [ImportacaoPdvController::class, 'store'])->name('pdvs.importar.enviar');
            Route::get('/pdvs/importar/{importacao}', [ImportacaoPdvController::class, 'show'])->name('pdvs.importar.revisar');
            Route::post('/pdvs/importar/{importacao}', [ImportacaoPdvController::class, 'update'])->name('pdvs.importar.gravar');
            Route::delete('/pdvs/importar/{importacao}', [ImportacaoPdvController::class, 'destroy'])->name('pdvs.importar.descartar');

            Route::get('/pdvs/cep/{cep}', [PdvController::class, 'cep'])->where('cep', '[0-9-]{8,9}')->middleware('throttle:60,1')->name('pdvs.cep');
            Route::get('/pdvs/localizar', [PdvController::class, 'localizar'])->middleware('throttle:30,1')->name('pdvs.localizar');
            Route::resource('pdvs', PdvController::class)
                ->only(['create', 'store', 'edit', 'update'])
                ->parameters(['pdvs' => 'pdv']);

            foreach (['regioes' => RegiaoController::class, 'redes' => RedeController::class] as $prefixo => $controller) {
                Route::get("/{$prefixo}", [$controller, 'index'])->name("{$prefixo}.index");
                Route::get("/{$prefixo}/novo", [$controller, 'create'])->name("{$prefixo}.create");
                Route::post("/{$prefixo}", [$controller, 'store'])->name("{$prefixo}.store");
                Route::get("/{$prefixo}/{id}/editar", [$controller, 'edit'])->whereNumber('id')->name("{$prefixo}.edit");
                Route::put("/{$prefixo}/{id}", [$controller, 'update'])->whereNumber('id')->name("{$prefixo}.update");
            }
        });

        // Agências que atendem a indústria: só consulta (o vínculo é feito pelo painel central).
        Route::get('/agencias', [AgenciaController::class, 'index'])
            ->middleware('perfil:admin_instalacao,admin_agencia,supervisor,gerente_trade,representante')
            ->name('agencias.index');

        Route::middleware('perfil:admin_instalacao')->group(function () {
            Route::resource('industrias', IndustriaController::class)
                ->except(['show', 'destroy'])
                ->parameters(['industrias' => 'industria']);
        });
    });
});

// API do app do promotor
Route::middleware([
    'api',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
    'cliente.ativo',
])->prefix('api/v1')->name('api.')->group(function () {
    Route::post('/login', [ApiAuthController::class, 'login'])->middleware('throttle:login')->name('login');

    Route::middleware(['auth:sanctum', AbrirOperacaoDaAgencia::class])->group(function () {
        Route::get('/me', [ApiAuthController::class, 'me'])->name('me');
        Route::post('/logout', [ApiAuthController::class, 'logout'])->name('logout');
        // Na agência, soma as visitas de todas as indústrias atendidas.
        Route::get('/roteiro', [ApiRoteiroController::class, 'show'])->name('roteiro');

        $execucao = function () {
            Route::get('/visitas/{visita}', [VisitaController::class, 'apiShow'])->whereNumber('visita')->name('visitas.show');
            Route::post('/visitas/{visita}/fotos', [ExecucaoVisitaController::class, 'foto'])->whereNumber('visita')->name('visitas.enviar-foto');
            Route::get('/visitas/{visita}/fotos/{foto}', [VisitaController::class, 'foto'])->whereNumber(['visita', 'foto'])->name('visitas.fotos');
            Route::post('/visitas/{visita}/concluir', [ExecucaoVisitaController::class, 'concluir'])->whereNumber('visita')->name('visitas.concluir');
            Route::post('/visitas/{visita}/check-in', CheckInController::class)->whereNumber('visita')->name('visitas.check-in');
            Route::get('/skus/{sku}/foto', [ApiRoteiroController::class, 'fotoSku'])->name('skus.foto');
        };
        // Execução na própria instalação da indústria...
        $execucao();
        // ...ou pela agência, com o código da indústria na rota (o app entra pela agência).
        Route::prefix('industrias/{industria}')->name('op.')->group($execucao);
    });
});
