<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController as ApiAuthController;
use App\Http\Controllers\Tenant\AgenciaController;
use App\Http\Controllers\Tenant\AuthController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\IndustriaController;
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
