<?php

namespace App\Http\Middleware;

use App\Services\NegocioDelUsuario;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puerta del panel del tendero.
 *
 * Tercera puerta del proyecto, hermana de `EnsureAdmin` y `EnsureComplexStaff`
 * y separada de las dos por el mismo motivo: son edificios distintos, y
 * relajar una para que deje pasar un rol nuevo amplía en silencio quién llega
 * a los otros.
 *
 * LO QUE RESUELVE
 *
 * Los endpoints que la app móvil usa para el tendero reciben `business_id` en
 * el cuerpo y lo creen. `POST /v1/orders/business` con el número de otra
 * tienda devuelve sus pedidos, con nombre, teléfono y dirección de cada
 * comprador. Es el mismo agujero que tenían los conjuntos antes de
 * `complex_staff`: los permisos del proyecto son por módulo y nunca por fila.
 *
 * Acá el negocio NO se cree: se resuelve desde la sesión, se comprueba contra
 * la cadena de propiedad y recién entonces se deja puesto.
 *
 * POR QUÉ ADEMÁS SE ESCRIBE EN LA PETICIÓN
 *
 * `$request->merge()` pisa lo que haya mandado el cliente con el identificador
 * ya verificado. Eso permite que las rutas nuevas apunten a los controladores
 * que YA existen —los mismos que atiende la app— sin reescribirlos y sin que
 * puedan recibir un número ajeno. Si en vez de eso cada controlador tuviera
 * que acordarse de leer el atributo, el día que uno se olvide no falla: sirve
 * los datos de otro.
 *
 * Las dos grafías van a propósito. En la base la columna es `busines_id` (con
 * una ese) y media docena de controladores validan `business_id` (con dos).
 * Poner solo una dejaría la mitad de las pantallas rechazando la petición por
 * un campo faltante.
 *
 * CUÁL NEGOCIO, CUANDO HAY VARIOS
 *
 * En la cabecera `X-Negocio`, no en la URL. El panel la pone una sola vez en
 * su cliente HTTP y así ninguna pantalla puede olvidarse de mandarla —
 * olvidarse no daría un error, daría los datos del otro local—. El precio es
 * que una dirección no dice qué negocio se está mirando y no se puede
 * compartir un enlace a un local concreto; para un panel que usa una sola
 * persona a la vez, y donde el negocio activo es un modo de sesión, es el
 * lado bueno del intercambio.
 *
 * Uso: `->middleware('negocio')`.
 */
class EnsureBusinessOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (!$usuario) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $negocios = app(NegocioDelUsuario::class)->negociosDe((int) $usuario->user_id);

        if ($negocios->isEmpty()) {
            /*
             * 403 y no 404: la cuenta existe y está bien autenticada, lo que
             * falta es que alguien le ate un negocio. El mensaje dice qué
             * hacer, porque quien lo lee no puede resolverlo solo.
             */
            return response()->json([
                'message' => 'Tu cuenta no administra ningún negocio. Pídele al equipo que te asigne uno.',
            ], 403);
        }

        $pedido = $request->header('X-Negocio');

        if ($pedido !== null && $pedido !== '') {
            $activo = $negocios->firstWhere('busines_id', (int) $pedido);

            /*
             * Un negocio que no es suyo no cae al primero en silencio: eso
             * enseñaría los datos de su propia tienda bajo el nombre de otra y
             * nadie se enteraría. Se rechaza y se dice por qué.
             */
            if (!$activo) {
                return response()->json([
                    'message' => 'Ese negocio no es tuyo.',
                ], 403);
            }
        } else {
            $activo = $negocios->first();
        }

        $request->attributes->set('busines_id', (int) $activo->busines_id);
        $request->attributes->set('negocio', $activo);
        $request->attributes->set('negocios', $negocios);

        $request->merge([
            'business_id' => (int) $activo->busines_id,
            'busines_id'  => (int) $activo->busines_id,
        ]);

        App::setLocale('es');

        return $next($request);
    }
}
