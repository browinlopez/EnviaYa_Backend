<?php

declare(strict_types=1);

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
         * EL TOKEN YA RESUELTO SI LO HAY, Y SI NO, A MANO.
         *
         * Medido en el log de latencia: `personal_access_tokens` salía DOS
         * veces en toda ruta con sesión —la búsqueda de Sanctum y la de aquí—.
         * Este middleware va en el grupo `api`, que corría ANTES del
         * `auth:sanctum` de cada ruta, así que no tenía usuario que mirar y no
         * le quedaba otra que buscarlo él.
         *
         * Y NO HACE FALTA MOVER NADA DE SITIO, que es lo que yo creía. Dije
         * que quitar la consulta pedía reordenar este middleware detrás de
         * `auth:sanctum` con `appendToPriorityList`, y que mover el orden de
         * un middleware de seguridad en 325 endpoints no lo pagaba una
         * consulta. Lo implementé así, lo probé rompiéndolo, y al quitar la
         * prioridad para ver si la red avisaba **la consulta seguía sin
         * duplicarse**: `$request->user()` resuelve el guard de Sanctum por su
         * cuenta, sin esperar al middleware de autenticación, y `auth:sanctum`
         * reutiliza después lo ya resuelto. El reordenamiento se deshizo.
         *
         * LA BÚSQUEDA A MANO SE QUEDA COMO RESPALDO, y es lo que impide ceder
         * nada: en las 21 rutas de `/v1` que no piden sesión no hay nadie que
         * resuelva un token, y sin el respaldo la credencial biométrica
         * dejaría de estar acotada en ellas. No ganaría privilegio —en una
         * ruta pública tener este token es igual que no tener ninguno— pero
         * una propiedad se cumple o no se cumple. Hay una prueba.
         *
         * Resultado: una consulta menos en toda petición donde el token se
         * pueda identificar, y exactamente lo de antes donde no.
         */
        $bearer = $request->bearerToken();

        if (! $bearer) {
            return $next($request);
        }

        $token = $this->tokenYaResuelto($request, $bearer)
            ?? PersonalAccessToken::findToken($bearer);

        // Sin token de por medio —sesión de navegador, ruta pública— no aplica.
        if (! $token || $token->name !== AccesoBiometricoController::NOMBRE_TOKEN) {
            return $next($request);
        }

        if (trim($request->path(), '/') === self::PERMITIDA) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Esta credencial solo sirve para volver a entrar con tu huella.',
            'reason' => 'biometric_token_scope',
        ], 403);
    }

    /**
     * El token que ya resolvio la autenticacion, SOLO si es el del encabezado.
     *
     * Aqui esta todo el cuidado del cambio, y la comprobacion de identidad no
     * es paranoia: se escribio despues de que la prueba «esa credencial no
     * sirve para nada mas» se pusiera roja.
     *
     * `currentAccessToken()` y el encabezado PUEDEN discrepar:
     *
     *  · con sesion de navegador —`EnsureFrontendRequestsAreStateful` y una
     *    cookie— devuelve un `TransientToken`, que no es un token de verdad y
     *    no tiene nombre;
     *  · y bajo `Sanctum::actingAs` devuelve un doble de Mockery, tambien sin
     *    nombre. Eso es de pruebas, pero fue lo que lo delato: el middleware
     *    dejaba pasar la credencial biometrica porque el token resuelto no se
     *    llamaba como ella.
     *
     * Confiar en el resuelto a secas convierte «no tiene nombre» en «no es
     * biometrico», que es justo lo contrario de lo que hay que concluir. Asi
     * que se usa unicamente cuando su clave coincide con el id que viene en el
     * encabezado —un token de Sanctum es `<id>|<secreto>`— y en cualquier otro
     * caso se busca a mano, como antes.
     *
     * En produccion con token Bearer los dos son el mismo objeto, asi que la
     * consulta de mas desaparece en las 304 rutas con sesion. Donde no se puede
     * probar que lo son, se paga la consulta: es mas barata que una credencial
     * sin acotar.
     */
    private function tokenYaResuelto(Request $request, string $bearer): ?PersonalAccessToken
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        $idDelEncabezado = explode('|', $bearer, 2)[0];

        if ((string) $token->getKey() !== $idDelEncabezado || $idDelEncabezado === '') {
            return null;
        }

        return $token;
    }
}
