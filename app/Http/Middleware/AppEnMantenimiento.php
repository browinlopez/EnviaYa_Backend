<?php

namespace App\Http\Middleware;

use App\Services\Ajustes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MODO MANTENIMIENTO DE LA APP MÓVIL
 *
 * Encendido desde el panel, la operación se para: compradores y domiciliarios
 * reciben 503 con el mensaje que se escribió, en vez de errores crudos mientras
 * alguien despliega.
 *
 * Se aplica EN EL SERVIDOR y no solo en la app. Si la app fuera la única que
 * mira la bandera, bastaría una versión vieja —o una que no la consulte— para
 * seguir creando pedidos en medio del despliegue, que es justo lo que se está
 * tratando de evitar.
 *
 * TRES EXCEPCIONES, y cada una por un motivo concreto:
 *
 *  · `/v1/admin/*` — el panel tiene que seguir vivo. Si el mantenimiento
 *    también lo apagara, no habría forma de volver a apagarlo: quedaría
 *    encendido hasta que alguien entrara al servidor por consola.
 *
 *  · los avisos de la pasarela de pago — un cobro en curso se confirma con un
 *    webhook. Rechazarlo con 503 significa perder la confirmación de un pago que
 *    el cliente ya hizo.
 *
 *  · `app/config` y el inicio de sesión — la app necesita poder preguntar qué
 *    pasa y el personal necesita poder entrar al panel.
 *
 * Además, quien es personal de la empresa (rol 4) pasa igual: el panel llama a
 * endpoints que no están bajo `/admin`, y bloquearlos lo dejaría a medias.
 */
class AppEnMantenimiento
{
    /** Rutas que siguen funcionando con el mantenimiento encendido. */
    private const EXENTAS = [
        'v1/app/config',
        'v1/admin/*',
        'v1/webhooks/*',
        'v1/login',
        'v1/logout',
        'v1/register',
        'v1/forgot-password',
        'v1/reset-password',
        // Comprobación de salud del servidor: la usa el monitoreo, no la app.
        'up',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (!Ajustes::valor('app.mantenimiento')) {
            return $next($request);
        }

        if ($request->is(...self::EXENTAS)) {
            return $next($request);
        }

        // El personal de la empresa no queda fuera: el panel consulta endpoints
        // que no viven bajo /admin.
        if ((int) ($request->user()->rol ?? 0) === 4) {
            return $next($request);
        }

        return response()->json([
            'message'     => (string) Ajustes::valor('app.mantenimiento_mensaje'),
            'maintenance' => true,
        ], 503);
    }
}
