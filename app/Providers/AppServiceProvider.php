<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->environment('production')) {
            URL::forceScheme('https');
            URL::forceRootUrl(config('app.url'));
        }

        // Laravel aplica el limitador 'api' a TODO el grupo api y por defecto
        // es 60/min por usuario/IP: la app dispara ~12 peticiones por arranque
        // más el polling de chats, así que ese presupuesto compartido se
        // agotaba y endpoints como top-businesses recibían 429 (lista de
        // negocios vacía). Este límite alto es solo la red de seguridad
        // global; los límites estrictos por ruta (login, registro, etc.)
        // siguen definidos en routes/api.php.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(1000)->by(
                $request->user()?->user_id ?: $request->ip(),
            );
        });
    }
}
