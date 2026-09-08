<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Auth\AccesoBiometricoController;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * El token de la huella no sirve para nada más.
 *
 * ESTO ES LO QUE HACE VERDAD LA PROMESA. Sanctum guarda las habilidades de
 * cada token pero **no las aplica solo**: `tokenCan()` es una comprobación que
 * hay que escribir. Un token creado con `['biometrico']` entra sin problemas
 * en cualquier ruta protegida solo con `auth:sanctum`.
 *
 * O sea que, sin este middleware, el token que queda guardado en el teléfono
 * valdría para leer pedidos, pagar y cambiar datos: exactamente lo que se dice
 * que no puede hacer. La frase «solo sirve para pedir una sesión» sería falsa
 * y nadie lo notaría hasta que alguien extrajera uno.
 *
 * Va en el grupo `api` entero y no ruta por ruta a propósito: una lista de
 * rutas a proteger se queda incompleta el día que alguien agregue un endpoint,
 * y el fallo sería silencioso.
 */
class SoloParaCambiarPorSesion
{
    /** La única ruta que este token puede tocar. */
    private const PERMITIDA = 'v1/biometrico/entrar';

    public function handle(Request $request, Closure $next): Response
    {
        /*
         * El token se busca A MANO, no por `$request->user()`.
         *
         * Este middleware va en el grupo `api`, que corre ANTES que el
         * `auth:sanctum` de cada ruta: ahí todavía no hay usuario resuelto y
         * `$request->user()` es null. Confiar en él dejaba pasar todo, que es
         * lo que descubrió la prueba «esa credencial no sirve para nada más».
         *
         * Buscarlo por el encabezado funciona en cualquier orden.
         */
        $bearer = $request->bearerToken();

        if (!$bearer) {
            return $next($request);
        }

        $token = PersonalAccessToken::findToken($bearer);

        // Sin token de por medio —sesión de navegador, ruta pública— no aplica.
        if (!$token || $token->name !== AccesoBiometricoController::NOMBRE_TOKEN) {
            return $next($request);
        }

        if (trim($request->path(), '/') === self::PERMITIDA) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Esta credencial solo sirve para volver a entrar con tu huella.',
            'reason'  => 'biometric_token_scope',
        ], 403);
    }
}
