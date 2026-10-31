<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController as ApiAuthController;
use App\Http\Controllers\Api\CheckInController;
use App\Http\Controllers\Api\ExecucaoVisitaController;
use App\Http\Controllers\Api\RoteiroController as ApiRoteiroController;
use App\Http\Controllers\Tenant\AcessoParceriaController;
use App\Http\Controllers\Tenant\AgenciaController;
use App\Http\Controllers\Tenant\AlertaController;
use App\Http\Controllers\Tenant\AuthController;
use App\Http\Controllers\Tenant\ContratoVisitaController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\DemandaController;
use App\Http\Controllers\Tenant\ImportacaoPdvController;
use App\Http\Controllers\Tenant\IndicadorController;
use App\Http\Controllers\Tenant\IndustriaController;
use App\Http\Controllers\Tenant\ParceriaController;
use App\Http\Controllers\Tenant\PdvController;
use App\Http\Controllers\Tenant\RedeController;
use App\Http\Controllers\Tenant\RegiaoController;
use App\Http\Controllers\Tenant\RoteiroController;
use App\Http\Controllers\Tenant\SkuController;
use App\Http\Controllers\Tenant\UsuarioController;
use App\Http\Controllers\Tenant\VisitaController;
use App\Http\Middleware\EnsureAcessoParceiro;
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

    Route::middleware('guest')->group(function () {
        Route::get('/entrar', [AuthController::class, 'create'])->name('login');
        Route::post('/entrar', [AuthController::class, 'store'])->middleware('throttle:login');
    });

    Route::get('/parcerias/entrar', [AcessoParceriaController::class, 'entrar'])->middleware('throttle:20,1')->name('parcerias.entrar');

    Route::middleware(['auth', EnsureAcessoParceiro::class])->group(function () {
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
        Route::get('/visitas/{visita}', [VisitaController::class, 'show'])->whereNumber('visita')->name('visitas.show');
        Route::get('/visitas/{visita}/fotos/{foto}', [VisitaController::class, 'foto'])->whereNumber(['visita', 'foto'])->name('visitas.fotos');
        Route::get('/parcerias', [ParceriaController::class, 'index'])->name('parcerias.index');
        Route::patch('/parcerias/{parceria}/regiao', [ParceriaController::class, 'regiao'])->whereNumber('parceria')->name('parcerias.regiao');
        Route::post('/parcerias', [ParceriaController::class, 'solicitar'])->name('parcerias.solicitar');
        Route::patch('/parcerias/{parceria}/responder', [ParceriaController::class, 'responder'])->whereNumber('parceria')->name('parcerias.responder');
        Route::delete('/parcerias/{parceria}', [ParceriaController::class, 'cancelar'])->whereNumber('parceria')->name('parcerias.cancelar');
        Route::put('/parcerias/perfil/agencia', [ParceriaController::class, 'perfil'])->name('parcerias.perfil');
        Route::post('/parcerias/{parceria}/abrir', [AcessoParceriaController::class, 'abrir'])->whereNumber('parceria')->name('parcerias.abrir');
        Route::post('/sair', [AuthController::class, 'destroy'])->name('logout');
        Route::get('/painel', DashboardController::class)->name('dashboard');

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

        // Agência e indústria consultam suas prestadoras; só o admin da instalação altera cadastros.
        Route::get('/agencias', [AgenciaController::class, 'index'])
            ->middleware('perfil:admin_instalacao,admin_agencia,supervisor,gerente_trade,representante')
            ->name('agencias.index');

        Route::middleware('perfil:admin_instalacao')->group(function () {
            Route::resource('industrias', IndustriaController::class)
                ->except(['show', 'destroy'])
                ->parameters(['industrias' => 'industria']);
            Route::resource('agencias', AgenciaController::class)
                ->only(['create', 'store', 'edit', 'update'])
                ->parameters(['agencias' => 'agencia']);
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

    Route::middleware(['auth:sanctum', EnsureAcessoParceiro::class])->group(function () {
        Route::get('/visitas/{visita}', [VisitaController::class, 'apiShow'])->whereNumber('visita')->name('visitas.show');
        Route::post('/visitas/{visita}/fotos', [ExecucaoVisitaController::class, 'foto'])->whereNumber('visita')->name('visitas.enviar-foto');
        Route::get('/visitas/{visita}/fotos/{foto}', [VisitaController::class, 'foto'])->whereNumber(['visita', 'foto'])->name('visitas.fotos');
        Route::post('/visitas/{visita}/concluir', [ExecucaoVisitaController::class, 'concluir'])->whereNumber('visita')->name('visitas.concluir');
        Route::get('/me', [ApiAuthController::class, 'me'])->name('me');
        Route::post('/logout', [ApiAuthController::class, 'logout'])->name('logout');
        Route::get('/roteiro', [ApiRoteiroController::class, 'show'])->name('roteiro');
        Route::post('/visitas/{visita}/check-in', CheckInController::class)
            ->whereNumber('visita')->name('visitas.check-in');
        Route::get('/skus/{sku}/foto', [ApiRoteiroController::class, 'fotoSku'])->name('skus.foto');
    });
});
