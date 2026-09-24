<?php

use App\Http\Controllers\Central\EmpresaController;
use Illuminate\Support\Facades\Route;

/*
 * API central, usada pelo app do promotor no primeiro acesso:
 * o promotor digita o código da empresa e o app descobre a URL da instalação.
 */

Route::get('/v1/empresas/{codigo}', [EmpresaController::class, 'show'])
    ->middleware('throttle:empresa')
    ->name('central.api.empresa');
