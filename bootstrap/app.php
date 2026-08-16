<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'v1',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
    $middleware->api(prepend: [
        \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        \Illuminate\Http\Middleware\HandleCors::class, // 👈 Este es el middleware de CORS
    ]);

    // php artisan serve responde sin Content-Length y el cuerpo llega cortado
    // a la app (ver comentario del middleware); en producción no hace nada.
    $middleware->append(\App\Http\Middleware\SetContentLengthForDevServer::class);

    $middleware->alias([
        'audit.api' => \App\Http\Middleware\AuditApiRequest::class,
        // Puerta del API de superadministración (rol 4). El panel de React
        // ya filtra en el cliente, pero eso no es una defensa.
        'admin'     => \App\Http\Middleware\EnsureAdmin::class,
    ]);
})
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
