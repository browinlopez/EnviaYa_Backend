<?php

use App\Http\Middleware\AppEnMantenimiento;
use App\Http\Middleware\AuditApiRequest;
use App\Http\Middleware\CeladorEnTurno;
use App\Http\Middleware\ClaveProvisional;
use App\Http\Middleware\CuentaHabilitada;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureBusinessOwner;
use App\Http\Middleware\EnsureCategoryAccess;
use App\Http\Middleware\EnsureComplexStaff;
use App\Http\Middleware\EnsureMediaAccess;
use App\Http\Middleware\EnsureModuleAccess;
use App\Http\Middleware\MedidaDeLaPeticion;
use App\Http\Middleware\SetContentLengthForDevServer;
use App\Http\Middleware\SoloParaCambiarPorSesion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpFoundation\Response;
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
            EnsureFrontendRequestsAreStateful::class,
            HandleCors::class, // 👈 Este es el middleware de CORS
        ]);

        // php artisan serve responde sin Content-Length y el cuerpo llega cortado
        // a la app (ver comentario del middleware); en producción no hace nada.
        $middleware->append(SetContentLengthForDevServer::class);

        /*
         * Cuánto tarda cada petición, una línea por petición.
         *
         * GLOBAL y no por ruta, por dos motivos. Una lista de rutas a medir
         * se queda incompleta el día que alguien añade un endpoint —y las que
         * faltan son justo las nuevas, que son las que nadie ha medido—. Y el
         * stack global es invisible para `gatherMiddleware()`, así que medir
         * no toca el congelado de las 325 rutas: comprobado, el fichero
         * `superficie-api.json` no menciona `SetContentLengthForDevServer`,
         * que lleva ahí arriba desde antes.
         *
         * Se apaga con `MEDIR_PETICIONES=false`, sin desplegar.
         */
        $middleware->append(MedidaDeLaPeticion::class);

        /*
         * Modo mantenimiento de la app móvil, encendido desde el panel.
         *
         * Va en el grupo `api` completo y no ruta por ruta: el objetivo es parar la
         * operación, y una lista de rutas a proteger se queda incompleta el día que
         * alguien agrega un endpoint. El middleware lleva su propia lista de
         * EXENTAS —el panel, los avisos de la pasarela y el inicio de sesión—, que
         * es la lista que sí se puede razonar.
         */
        $middleware->api(append: [
            AppEnMantenimiento::class,

            /*
             * El token que el telefono guarda tras la huella solo puede cambiarse
             * por una sesion. Va en el grupo entero porque Sanctum NO aplica las
             * habilidades por su cuenta: sin esto, esa credencial serviria para
             * leer pedidos y pagar.
             */
            SoloParaCambiarPorSesion::class,
        ]);

        $middleware->alias([
            'audit.api' => AuditApiRequest::class,
            // Una cuenta deshabilitada no sigue entrando con su token: va en el
            // grupo `auth:sanctum` entero, junto a `audit.api`.
            'cuenta.habilitada' => CuentaHabilitada::class,
            // Puerta del API de superadministración (rol 4). El panel de React
            // ya filtra en el cliente, pero eso no es una defensa.
            'admin' => EnsureAdmin::class,
            // Puerta por sección: `modulo:pagos` para consultar, `modulo:pagos,gestionar`
            // para modificar. Se aplica encima de `admin`, que ya validó que la
            // persona sea del equipo.
            'modulo' => EnsureModuleAccess::class,
            // Puerta del panel de aliados. Aparte de `admin` a propósito: son dos
            // edificios distintos y compartir la puerta ampliaría en silencio
            // quién llega a la administración de la plataforma.
            'conjunto' => EnsureComplexStaff::class,
            // El celador con la clave que le dio el administrador no trabaja
            // hasta que ponga la suya. Va detrás de `conjunto`.
            'clave.propia' => ClaveProvisional::class,
            'en.turno' => CeladorEnTurno::class,
            // Puerta del panel del tendero, hermana de la anterior. Deja puesto el
            // negocio de quien pide, ya comprobado contra la cadena de propiedad,
            // para que ningún controlador tenga que creerle un `business_id` al
            // cuerpo de la petición.
            'negocio' => EnsureBusinessOwner::class,
            // Puerta de los archivos. Aparte porque el módulo que manda depende de
            // la entidad de la URL: los de un negocio los rige `negocios` y los de
            // un banner, `marketing.banners`.
            'medios' => EnsureMediaAccess::class,
            // Puerta de las categorías. Aparte por lo mismo: una sola ruta sirve
            // las de producto y las de negocio, y son dos módulos distintos.
            'categorias' => EnsureCategoryAccess::class,
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
         * LOS ERRORES DE LARAVEL, EN ESPAÑOL.
         *
         * Las validaciones ya salen en español por `lang/es`, pero los
         * errores del propio framework (sesión vencida, sin permiso, ruta que
         * no existe, demasiados intentos, error del servidor) traen el texto
         * en inglés escrito a mano y no pasan por la traducción. Acá se
         * cambian SOLO esos textos genéricos; un `abort(403, 'Este pedido no
         * es tuyo')` escrito por nosotros llega tal cual.
         */
        $exceptions->respond(function (Response $respuesta) {
            if (! $respuesta instanceof JsonResponse) {
                return $respuesta;
            }

            $datos = $respuesta->getData(true);
            $texto = is_array($datos) ? ($datos['message'] ?? null) : null;
            $estado = $respuesta->getStatusCode();

            $porEstado = [
                401 => 'Tu sesión terminó. Vuelve a iniciar sesión.',
                403 => 'No tienes permiso para hacer esto.',
                404 => 'No encontramos lo que buscas.',
                405 => 'Esta acción no está disponible.',
                413 => 'El archivo es demasiado grande.',
                419 => 'La página venció. Recárgala e intenta de nuevo.',
                429 => 'Demasiados intentos. Espera un momento y vuelve a intentarlo.',
                500 => 'Algo falló de nuestro lado. Intenta de nuevo en un momento.',
                503 => 'Estamos en mantenimiento. Intenta de nuevo en unos minutos.',
            ];

            $generico = $texto === null || $texto === ''
                || preg_match('/^(Unauthenticated\.|This action is unauthorized\.|Forbidden|Not Found|Too Many Attempts\.|Server Error|Service Unavailable|Method Not Allowed|CSRF token mismatch\.|Page Expired|Request Entity Too Large|The route .* could not be found\.?|No query results for model .*|The \w+ method is not supported for route .*)$/s', $texto);

            if ($generico && isset($porEstado[$estado])) {
                $datos['message'] = $porEstado[$estado];
                $respuesta->setData($datos);
            }

            return $respuesta;
        });

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
