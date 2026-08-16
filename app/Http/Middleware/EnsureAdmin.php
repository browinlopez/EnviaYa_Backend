<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puerta del API de administración.
 *
 * El panel de React ya rechaza a quien no tenga rol 4, pero eso es una
 * comodidad de la interfaz, no una defensa: cualquiera puede llamar al
 * endpoint con curl y un token de tendero. La autorización real vive acá.
 */
class EnsureAdmin
{
    /** Tabla `rol`: 1 comprador, 2 tendero, 3 domiciliario, 4 admin. */
    private const ROL_ADMIN = 4;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        if ((int) $user->rol !== self::ROL_ADMIN) {
            return response()->json([
                'message' => 'Esta sección requiere permisos de administración.',
            ], 403);
        }

        // El panel está en español y muestra tal cual lo que responde un 422.
        // Se cambia solo para estas rutas: la app móvil ya está publicada y no
        // conviene cambiarle las cadenas de error de golpe.
        App::setLocale('es');

        return $next($request);
    }
}
