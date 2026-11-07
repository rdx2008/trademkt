<?php

namespace App\Providers;

use App\Services\Operacao\Equipe;
use App\Support\Contexto;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        // Tentativas de login (web e app): 5 por minuto por e-mail + IP
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        // Descoberta de empresa pelo app: 20 por minuto por IP
        RateLimiter::for('empresa', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        // A equipe da agência e o contexto da operação valem por uma requisição.
        Event::listen(RequestHandled::class, function () {
            Equipe::esquecer();
            Contexto::limpar();
        });
    }
}
