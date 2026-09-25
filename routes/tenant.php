<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController as ApiAuthController;
use App\Http\Controllers\Tenant\AgenciaController;
use App\Http\Controllers\Tenant\AuthController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\ImportacaoPdvController;
use App\Http\Controllers\Tenant\IndustriaController;
use App\Http\Controllers\Tenant\PdvController;
use App\Http\Controllers\Tenant\RedeController;
use App\Http\Controllers\Tenant\RegiaoController;
use App\Http\Controllers\Tenant\SkuController;
use App\Http\Controllers\Tenant\UsuarioController;
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

    Route::middleware('auth')->group(function () {
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

        Route::middleware('perfil:admin_instalacao')->group(function () {
            Route::resource('industrias', IndustriaController::class)
                ->except(['show', 'destroy'])
                ->parameters(['industrias' => 'industria']);
            Route::resource('agencias', AgenciaController::class)
                ->except(['show', 'destroy'])
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

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [ApiAuthController::class, 'me'])->name('me');
        Route::post('/logout', [ApiAuthController::class, 'logout'])->name('logout');
    });
});
