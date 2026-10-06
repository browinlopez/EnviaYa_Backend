<?php

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * EL TRAZO DE LA RUTA, CALCULADO AQUÍ Y NO EN EL TELÉFONO.
 *
 * La app llamaba directamente a Google Directions con la clave metida dentro
 * del paquete —la misma, además, que dibuja los mapas—. Una clave de servicio
 * web NO SE PUEDE restringir por aplicación: Google solo admite restringirla
 * por IP, y una app no tiene IP fija. Así que esa clave tenía que ir sin
 * restricción ninguna, y cualquiera que abra el APK la saca y factura a la
 * cuenta de VeciPa'Ya hasta que alguien mire el recibo.
 *
 * Pasando la llamada por aquí, la clave vive solo en el servidor y se puede
 * restringir a su IP y ponerle cuota diaria.
 *
 * Se devuelve la polilínea CODIFICADA, tal como la da Google: la app ya sabe
 * descodificarla y mandarla entera son decenas de miles de coordenadas por una
 * red móvil.
 */
class RutaDelPedidoController extends Controller
{
    /**
     * Media hora en caché.
     *
     * Dos domiciliarios que salen de la misma tienda al mismo barrio piden la
     * misma ruta, y el seguimiento la vuelve a pedir cada vez que se reabre la
     * pantalla. Cada una de esas es una llamada facturada.
     */
    private const MINUTOS_EN_CACHE = 30;

    public function __invoke(Request $request)
    {
        $datos = $request->validate([
            'origen'  => ['required', 'string', 'regex:/^-?\d+(\.\d+)?,-?\d+(\.\d+)?$/'],
            'destino' => ['required', 'string', 'regex:/^-?\d+(\.\d+)?,-?\d+(\.\d+)?$/'],
        ]);

        $clave = (string) config('services.google.maps_server_key');

        if ($clave === '') {
            Log::error('No hay GOOGLE_MAPS_SERVER_KEY: no se puede trazar la ruta');

            /*
             * 503 y no 500: no es que la petición esté mal, es que falta
             * configuración del servidor. La app lo trata como «sin ruta» y
             * pinta la línea recta, que es lo que hacía antes de que esto
             * existiera.
             */
            return response()->json([
                'message' => 'El trazado de rutas no está disponible.',
            ], 503);
        }

        $memoria = 'ruta:' . md5($datos['origen'] . '|' . $datos['destino']);

        $polilinea = Cache::remember(
            $memoria,
            now()->addMinutes(self::MINUTOS_EN_CACHE),
            function () use ($datos, $clave) {
                $respuesta = Http::timeout(8)->get(
                    'https://maps.googleapis.com/maps/api/directions/json',
                    [
                        'origin'      => $datos['origen'],
                        'destination' => $datos['destino'],
                        'key'         => $clave,
                        /* En moto, que es como se reparte acá. */
                        'mode'        => 'driving',
                    ],
                );

                if ($respuesta->failed()) {
                    throw new \RuntimeException($respuesta->body());
                }

                return $respuesta->json('routes.0.overview_polyline.points');
            },
        );

        return response()->json(['polilinea' => $polilinea]);
    }
}
