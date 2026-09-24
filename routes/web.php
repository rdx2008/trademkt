<?php

use App\Http\Controllers\Central\AuthController;
use App\Http\Controllers\Central\ClienteController;
use Illuminate\Support\Facades\Route;

/*
 * Painel central do fornecedor (só nos domínios em CENTRAL_DOMAINS).
 */

Route::redirect('/', '/clientes');

Route::middleware('guest:admin')->group(function () {
    Route::get('/entrar', [AuthController::class, 'create'])->name('central.login');
    Route::post('/entrar', [AuthController::class, 'store'])->middleware('throttle:login');
});

Route::middleware('auth:admin')->group(function () {
    Route::post('/sair', [AuthController::class, 'destroy'])->name('central.logout');

    Route::get('/clientes', [ClienteController::class, 'index'])->name('central.clientes.index');
    Route::get('/clientes/novo', [ClienteController::class, 'create'])->name('central.clientes.create');
    Route::post('/clientes', [ClienteController::class, 'store'])->name('central.clientes.store');
    Route::patch('/clientes/{cliente}/ativo', [ClienteController::class, 'alternarAtivo'])->name('central.clientes.ativo');
});
