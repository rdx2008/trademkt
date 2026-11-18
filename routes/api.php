<?php

use App\Http\Controllers\Central\EmpresaController;
use App\Http\Controllers\Central\LoginUnicoController;
use App\Http\Controllers\Central\VersaoDoAppController;
use Illuminate\Support\Facades\Route;

/*
 * API central, usada pelo app do promotor no primeiro acesso:
 * o promotor digita o código da empresa e o app descobre a URL da instalação.
 */

Route::get('/v1/empresas/{codigo}', [EmpresaController::class, 'show'])
    ->middleware('throttle:empresa')
    ->name('central.api.empresa');

Route::get('/v1/app', [VersaoDoAppController::class, 'show'])
    ->middleware('throttle:empresa')
    ->name('central.api.app');

// Login único: e-mail e senha, sem código da agência (devolve uma conta por agência onde a senha confere).
Route::post('/v1/entrar', [LoginUnicoController::class, 'entrar'])
    ->middleware('throttle:login')
    ->name('central.api.entrar');
