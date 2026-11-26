<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Una cuenta deshabilitada no sigue entrando con el token que ya tenía.
 *
 * `user.state` solo se miraba al iniciar sesión (y en la puerta del panel del
 * conjunto). Los tokens de Sanctum no caducan solos, así que a quien se
 * deshabilitaba desde el panel le seguía valiendo la sesión abierta en todo
 * el resto del API: pedidos, pagos, chats, el panel del negocio.
 *
 * Va en el grupo `auth:sanctum` entero, no ruta por ruta, por lo mismo que los
 * otros middlewares de grupo: una lista de rutas se queda incompleta el día
 * que alguien agrega un endpoint. Las rutas públicas (login, registro,
 * recuperar contraseña) no pasan por aquí.
 *
 * Además de rechazar, BORRA el token con el que se pidió: así la siguiente
 * petición ya ni siquiera llega autenticada, y rehabilitar la cuenta exige
 * volver a iniciar sesión en vez de revivir un token viejo.
 */
class CuentaHabilitada
{
    /** Cerrar sesión siempre se deja: es justo lo que debe poder hacer. */
    private const EXENTA = 'v1/logout';

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (! $usuario || $usuario->state) {
            return $next($request);
        }

        if (trim($request->path(), '/') === self::EXENTA) {
            return $next($request);
        }

        // Solo un token real: con sesión de navegador es un TransientToken y
        // bajo `Sanctum::actingAs` un doble de pruebas, y ninguno se borra.
        $token = $usuario->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Tu cuenta está deshabilitada. Comunícate con el administrador.',
            'reason' => 'account_disabled',
        ], 403);
    }
}
