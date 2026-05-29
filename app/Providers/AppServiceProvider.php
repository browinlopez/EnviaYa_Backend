<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            \App\Contracts\PaymentGatewayInterface::class,
            \App\Services\BoldService::class
        );
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

        \Illuminate\Support\Facades\Gate::define('viewApiDocs', function (?\App\Models\User $user) {
            return in_array(app()->environment(), ['local', 'testing']);
        });

        if (class_exists(\Dedoc\Scramble\Scramble::class)) {
            \Dedoc\Scramble\Scramble::routes(function (\Illuminate\Routing\Route $route) {
                // Filtrar para documentar todas las rutas que parezcan de la API
                $uri = $route->uri();
                return \Illuminate\Support\Str::startsWith($uri, ['v1', 'admin', 'auth', 'email', 'forgot-password', 'logout', 'profile', 'register', 'resend-verification'])
                       && ! \Illuminate\Support\Str::startsWith($uri, ['docs', '_ignition', 'laravel-verify-email', 'dashboard']);
            });
        }
    }
}
