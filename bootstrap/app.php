<?php

use App\Http\Middleware\AbrirOperacaoDaAgencia;
use App\Http\Middleware\EnsureClienteAtivo;
use App\Http\Middleware\EnsurePerfil;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Rotas centrais (painel do fornecedor + API de descoberta do app)
            // só respondem nos domínios centrais. As rotas de cada cliente
            // ficam em routes/tenant.php e são carregadas pelo TenancyServiceProvider.
            foreach (config('tenancy.central_domains') as $domain) {
                Route::middleware('web')
                    ->domain($domain)
                    ->group(base_path('routes/web.php'));

                Route::middleware('api')
                    ->prefix('api')
                    ->domain($domain)
                    ->group(base_path('routes/api.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'perfil' => EnsurePerfil::class,
            'cliente.ativo' => EnsureClienteAtivo::class,
        ]);
        // A agência abre o banco da indústria antes de os modelos da rota serem buscados.
        $middleware->prependToPriorityList(SubstituteBindings::class, AbrirOperacaoDaAgencia::class);

        $middleware->redirectGuestsTo(function (Request $request) {
            return in_array($request->getHost(), config('tenancy.central_domains'), true)
                ? route('central.login')
                : route('login');
        });

        $middleware->redirectUsersTo(function (Request $request) {
            return in_array($request->getHost(), config('tenancy.central_domains'), true)
                ? route('central.clientes.index')
                : route('dashboard');
        });

        // O Coolify (Traefik) fica na frente do app como proxy reverso.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
