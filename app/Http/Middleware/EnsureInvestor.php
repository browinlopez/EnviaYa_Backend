<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puerta del tablero del inversionista (rol 7).
 *
 * Cuarta puerta del proyecto, hermana de `EnsureAdmin`, `EnsureComplexStaff`
 * y `EnsureBusinessOwner`, y separada de las tres por el mismo motivo: son
 * edificios distintos, y relajar una para que deje pasar un rol nuevo amplía
 * en silencio quién llega a los otros. El inversionista no entra a `/negocio`
 * ni a `/conjunto`, y ninguno de ellos entra acá.
 *
 * No deja nada puesto en la petición: el inversionista no tiene un registro
 * propio que acotar. Lo que lo protege es lo que devuelve el servicio —solo
 * cifras agregadas, ver `App\Services\Inversionista`—, no un filtro por fila.
 *
 * Uso: `->middleware('inversionista')`.
 */
class EnsureInvestor
{
    /** Tabla `rol`: el 7 es el inversionista. */
    public const ROL_INVERSIONISTA = 7;

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (! $usuario) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        if ((int) $usuario->rol !== self::ROL_INVERSIONISTA) {
            return response()->json([
                'message' => 'Esta sección es para los inversionistas de VeciPa\'Ya.',
            ], 403);
        }

        /*
         * LA CUENTA DESHABILITADA, EN CADA PETICIÓN, como en las otras
         * puertas: los tokens de Sanctum no caducan solos, y a quien se
         * deshabilita desde el panel le seguiría valiendo el que ya tenía.
         * `cuenta.habilitada` ya lo mira en el grupo; esto es la segunda capa,
         * por si la ruta algún día se saca de ese grupo.
         */
        if (! $usuario->state) {
            return response()->json([
                'message' => 'Tu cuenta está deshabilitada. Comunícate con el administrador.',
                'reason' => 'account_disabled',
            ], 403);
        }

        App::setLocale('es');

        return $next($request);
    }
}
