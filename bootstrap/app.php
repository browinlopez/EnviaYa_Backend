<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        // Puerta por sección: `modulo:pagos` para consultar, `modulo:pagos,gestionar`
        // para modificar. Se aplica encima de `admin`, que ya validó que la
        // persona sea del equipo.
        'modulo'    => \App\Http\Middleware\EnsureModuleAccess::class,
        // Puerta de los archivos. Aparte porque el módulo que manda depende de
        // la entidad de la URL: los de un negocio los rige `negocios` y los de
        // un banner, `marketing.banners`.
        'medios'    => \App\Http\Middleware\EnsureMediaAccess::class,
    ]);
})
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Monitoreo de errores.
         *
         * Sin `SENTRY_LARAVEL_DSN` en el .env esto no hace nada: el paquete
         * queda inerte y no envía ni captura. Con DSN, un 500 en producción se
         * sabe en el momento en vez de cuando a un usuario le da por contarlo.
         *
         * Se registra igual —con o sin DSN— para que activarlo en el servidor
         * sea poner una variable y no tocar código.
         */
        Integration::handles($exceptions);

        /*
         * Lo que NO se reporta.
         *
         * Un 404 o un 403 no son fallos del sistema sino su funcionamiento
         * normal: el reparto por áreas rechaza rutas ajenas todo el día, y
         * mandarlas al monitor ahogaría los errores de verdad en ruido. Un 422
         * es validación: alguien escribió algo mal, no se rompió nada.
         */
        $exceptions->dontReport([
            AuthenticationException::class,
            AuthorizationException::class,
            ValidationException::class,
            NotFoundHttpException::class,
            ModelNotFoundException::class,
        ]);
    })->create();
