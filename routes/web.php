<?php

use App\Http\Controllers\Central\AuthController;
use App\Http\Controllers\Central\ClienteController;
use App\Http\Controllers\Central\DashboardController;
use App\Http\Controllers\Central\DominioController;
use App\Http\Controllers\Central\RedeAgenciaController;
use Illuminate\Support\Facades\Route;

/*
 * Painel central do fornecedor (só nos domínios em CENTRAL_DOMAINS).
 */

Route::redirect('/', '/visao-geral');

Route::middleware('guest:admin')->group(function () {
    Route::get('/entrar', [AuthController::class, 'create'])->name('central.login');
    Route::post('/entrar', [AuthController::class, 'store'])->middleware('throttle:login');
});

Route::middleware('auth:admin')->group(function () {
    Route::get('/rede-agencias', [RedeAgenciaController::class, 'index'])->name('central.rede.index');
    Route::post('/rede-agencias', [RedeAgenciaController::class, 'store'])->name('central.rede.store');
    Route::get('/rede-agencias/{agencia}/editar', [RedeAgenciaController::class, 'edit'])->whereNumber('agencia')->name('central.rede.edit');
    Route::put('/rede-agencias/{agencia}', [RedeAgenciaController::class, 'update'])->whereNumber('agencia')->name('central.rede.update');
    Route::get('/visao-geral', DashboardController::class)->name('central.dashboard');
    Route::post('/sair', [AuthController::class, 'destroy'])->name('central.logout');

    Route::get('/clientes', [ClienteController::class, 'index'])->name('central.clientes.index');
    Route::get('/clientes/{cliente}/dominios', [DominioController::class, 'index'])->name('central.dominios.index');
    Route::post('/clientes/{cliente}/dominios', [DominioController::class, 'store'])->name('central.dominios.store');
    Route::patch('/clientes/{cliente}/dominios/{dominio}/principal', [DominioController::class, 'principal'])->whereNumber('dominio')->name('central.dominios.principal');
    Route::delete('/clientes/{cliente}/dominios/{dominio}', [DominioController::class, 'destroy'])->whereNumber('dominio')->name('central.dominios.destroy');
    Route::get('/clientes/novo', [ClienteController::class, 'create'])->name('central.clientes.create');
    Route::post('/clientes', [ClienteController::class, 'store'])->name('central.clientes.store');
    Route::patch('/clientes/{cliente}/ativo', [ClienteController::class, 'alternarAtivo'])->name('central.clientes.ativo');
});
