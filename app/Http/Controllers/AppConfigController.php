<?php

namespace App\Http\Controllers;

use App\Services\Ajustes;
use Illuminate\Http\Request;

/**
 * LO QUE LA APP MÓVIL PREGUNTA ANTES DE EMPEZAR
 *
 * Es PÚBLICO a propósito: la app tiene que poder saber que la plataforma está en
 * mantenimiento o que su versión ya no sirve ANTES de intentar iniciar sesión.
 * Detrás del token, una app vieja que ya no puede autenticarse tampoco podría
 * enterarse de que debe actualizarse — se quedaría en un error sin explicación.
 *
 * No devuelve nada sensible: dos banderas, dos versiones, dos mensajes escritos
 * para leerse en pantalla y la tarifa de domicilio —que el cliente ve igualmente
 * en cuanto arma un carrito—.
 *
 * El servidor ADEMÁS lo aplica (ver `AppEnMantenimiento`). Esto es para que la
 * app pueda decirlo bien; no es la defensa.
 */
class AppConfigController extends Controller
{
    public function __invoke(Request $request)
    {
        $mantenimiento = (bool) Ajustes::valor('app.mantenimiento');

        $plataforma = strtolower((string) $request->query('platform', ''));

        $minima = match ($plataforma) {
            'android' => (string) Ajustes::valor('app.version_minima_android'),
            'ios'     => (string) Ajustes::valor('app.version_minima_ios'),
            default   => null,
        };

        return response()->json([
            'maintenance' => [
                'active'  => $mantenimiento,
                'message' => (string) Ajustes::valor('app.mantenimiento_mensaje'),
            ],
            /*
             * Reglas que la app necesita para PINTAR bien antes de pedir.
             *
             * La tarifa la calcula el servidor al crear el pedido y esa es la
             * que se cobra; esto es para que el carrito muestre el mismo número
             * y no uno escrito a mano en el código de la app, que es lo que
             * hacía hasta ahora.
             */
            'operation' => [
                'delivery_fee' => (int) Ajustes::valor('operacion.tarifa_domicilio'),
                /*
                 * El plazo prometido. La app lo pintaba como "15min" en las
                 * fichas y arrancaba el reloj del seguimiento en 20, dos
                 * constantes escritas a mano que ningún dato respaldaba: el
                 * campo `estimated_time` que leía no existe en el servidor.
                 * Ahora es un compromiso de la operación, se ajusta en el
                 * panel y contra él se mide cada entrega.
                 */
                'delivery_time_minutes' => (int) Ajustes::valor('operacion.tiempo_entrega_min'),
            ],
            'version' => [
                // Con `?platform=` viene la que toca; sin él, las dos, para que
                // la app decida. Así el endpoint sirve igual antes de que la app
                // sepa mandar su plataforma.
                'minimum'         => $minima,
                'minimum_android' => (string) Ajustes::valor('app.version_minima_android'),
                'minimum_ios'     => (string) Ajustes::valor('app.version_minima_ios'),
                'message'         => (string) Ajustes::valor('app.mensaje_actualizacion'),
            ],
        ]);
    }
}
