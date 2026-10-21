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
     * UN MES EN CACHE, Y NO MEDIA HORA.
     *
     * El camino de una tienda a una casa es la misma calle hoy y el mes que
     * viene. Con media hora, la misma tienda repartiendo dos veces en la tarde
     * al mismo edificio pagaba dos llamadas a Google; con un mes, paga una.
     *
     * La clave es el par origen-destino, y los dos extremos son FIJOS: el
     * origen es la tienda y el destino es una direccion guardada. Es decir,
     * esto es en la practica una cache por tienda y direccion, que es
     * exactamente lo que se queria.
     *
     * Un mes y no para siempre porque las calles sí cambian de vez en cuando
     * --una obra, un sentido invertido-- y porque una entrada que nunca vence
     * no se puede corregir sin entrar a mano a borrarla.
     */
    private const MINUTOS_EN_CACHE = 60 * 24 * 30;

    /**
     * Los decimales con los que se construye la clave.
     *
     * Cinco decimales son algo mas de un metro: suficiente para que «la misma
     * tienda» y «la misma casa» caigan siempre en la misma entrada aunque el
     * cliente mande la coordenada con mas cifras, y poco suficiente para no
     * mezclar dos casas distintas.
     */
    private const DECIMALES_DE_LA_CLAVE = 5;

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

        /*
         * La clave se normaliza antes de resumirla: «10.9878,-74.7889» y
         * «10.98780,-74.78890» son el mismo sitio y tenian que caer en la
         * misma entrada. El prefijo lleva version para poder invalidar todo
         * de golpe el dia que haya que cambiar como se traza.
         */
        $memoria = 'ruta:v2:' . md5(
            $this->normalizar($datos['origen']) . '|' . $this->normalizar($datos['destino']),
        );

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

    /** «lat,lng» con los decimales fijos, para que la clave no dependa del formato. */
    private function normalizar(string $punto): string
    {
        [$lat, $lng] = array_map('floatval', explode(',', $punto));

        return number_format($lat, self::DECIMALES_DE_LA_CLAVE, '.', '')
            . ',' . number_format($lng, self::DECIMALES_DE_LA_CLAVE, '.', '');
    }
}
