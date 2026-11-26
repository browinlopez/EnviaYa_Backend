<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Conjunto\ComplexStaff;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El celador con clave provisional no trabaja hasta que la cambie.
 *
 * Va sobre el grupo `conjunto` entero —portería incluida— y no ruta por ruta:
 * una lista de rutas a proteger se queda corta el día que alguien agrega un
 * endpoint. Lo único que se deja pasar es `conjunto/me`, porque el panel lo
 * necesita para saber de qué conjunto es la persona y llevarla a la pantalla
 * de «Crea tu clave». El perfil, el cambio de clave y cerrar sesión viven
 * fuera de `/conjunto` y no pasan por aquí.
 *
 * Solo al CELADOR: es el único al que se le enciende la marca, y mirar el rol
 * evita que una fila mal puesta bloquee a un administrador o a un tendero.
 */
class ClaveProvisional
{
    private const EXENTAS = ['v1/conjunto/me'];

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (! $usuario
            || ! $usuario->must_change_password
            || (int) $usuario->rol !== ComplexStaff::ROL_CELADOR
            || in_array(trim($request->path(), '/'), self::EXENTAS, true)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Antes de seguir, crea tu propia clave.',
            'reason' => 'must_change_password',
        ], 403);
    }
}
