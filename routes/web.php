<?php

use App\Http\Controllers\Central\AuthController;
use App\Http\Controllers\Central\ClienteController;
use App\Http\Controllers\Central\DashboardController;
use App\Http\Controllers\Central\DominioController;
use App\Http\Controllers\Central\MarcaClienteController;
use App\Http\Controllers\Central\SiteInstitucionalController;
use App\Http\Controllers\Central\VinculoController;
use App\Http\Controllers\InstitucionalController;
use Illuminate\Support\Facades\Route;

/*
 * Painel central do fornecedor (só nos domínios em CENTRAL_DOMAINS).
 */

Route::get('/', [InstitucionalController::class, 'index'])->name('institucional.home');
Route::get('/privacidade', [InstitucionalController::class, 'privacidade'])->name('institucional.privacidade');
Route::get('/institucional/arquivos/{arquivo}', [InstitucionalController::class, 'arquivo'])->name('institucional.arquivo');
Route::post('/demonstracao', [InstitucionalController::class, 'contato'])->middleware('throttle:5,10')->name('institucional.contato');

Route::middleware('guest:admin')->group(function () {
    Route::get('/'.config('institucional.acesso'), [AuthController::class, 'create'])->name('central.login');
    Route::post('/'.config('institucional.acesso'), [AuthController::class, 'store'])->middleware('throttle:login');
});

Route::middleware('auth:admin')->group(function () {
    Route::prefix(config('institucional.acesso').'/site')->name('central.site.')->controller(SiteInstitucionalController::class)->group(function () {
        Route::get('/', 'edit')->name('edit');
        Route::put('/', 'update')->name('update');
        Route::get('/previa', 'previa')->name('previa');
        Route::get('/contatos', 'contatos')->name('contatos');
        Route::patch('/contatos/{contato}', 'situacao')->name('situacao');
        Route::delete('/contatos/{contato}', 'excluirContato')->name('excluir-contato');
    });
    Route::get('/vinculos', [VinculoController::class, 'index'])->name('central.vinculos.index');
    Route::post('/vinculos', [VinculoController::class, 'store'])->name('central.vinculos.store');
    Route::get('/vinculos/{vinculo}/editar', [VinculoController::class, 'edit'])->whereNumber('vinculo')->name('central.vinculos.edit');
    Route::put('/vinculos/{vinculo}', [VinculoController::class, 'update'])->whereNumber('vinculo')->name('central.vinculos.update');
    Route::get('/visao-geral', DashboardController::class)->name('central.dashboard');
    Route::post('/sair', [AuthController::class, 'destroy'])->name('central.logout');

    Route::get('/clientes', [ClienteController::class, 'index'])->name('central.clientes.index');
    Route::get('/clientes/{cliente}/marca', [MarcaClienteController::class, 'edit'])->name('central.clientes.marca');
    Route::put('/clientes/{cliente}/marca', [MarcaClienteController::class, 'update'])->name('central.clientes.marca.salvar');
    Route::get('/clientes/{cliente}/dominios', [DominioController::class, 'index'])->name('central.dominios.index');
    Route::post('/clientes/{cliente}/dominios', [DominioController::class, 'store'])->name('central.dominios.store');
    Route::patch('/clientes/{cliente}/dominios/{dominio}/principal', [DominioController::class, 'principal'])->whereNumber('dominio')->name('central.dominios.principal');
    Route::delete('/clientes/{cliente}/dominios/{dominio}', [DominioController::class, 'destroy'])->whereNumber('dominio')->name('central.dominios.destroy');
    Route::get('/clientes/novo', [ClienteController::class, 'create'])->name('central.clientes.create');
    Route::post('/clientes', [ClienteController::class, 'store'])->name('central.clientes.store');
    Route::patch('/clientes/{cliente}/ativo', [ClienteController::class, 'alternarAtivo'])->name('central.clientes.ativo');
});
