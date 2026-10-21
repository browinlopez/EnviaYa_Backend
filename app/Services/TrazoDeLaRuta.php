<?php

namespace App\Services;

use App\Models\Order\OrdersSales;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * EL TRAZO DE UNA RUTA, EN UN SOLO SITIO
 *
 * Nació partido: el controlador de `/rutas` llamaba a Google y cacheaba, y el
 * pedido no sabía nada de su propio camino. Acá viven las dos cosas, porque
 * son la misma pregunta hecha desde sitios distintos.
 *
 * TRES CAPAS, de la más barata a la más cara:
 *
 *  1. LA DEL PEDIDO. Si el pedido ya tiene su polilínea guardada, se devuelve
 *     y se acabó. Es el suelo: una llamada a Google por pedido y ninguna más,
 *     ni aunque se borre la caché, ni aunque lo abran el comprador, el
 *     domiciliario, el panel y la torre.
 *
 *  2. LA CACHÉ POR TIENDA Y DIRECCIÓN. Un mes. La misma tienda repartiendo al
 *     mismo edificio cincuenta veces paga una. Es la que evita pagar el primer
 *     trazo de cada pedido nuevo.
 *
 *  3. GOOGLE.
 *
 * LA CLAVE DE LA CACHÉ NO PUEDE LLEVAR LA POSICIÓN DEL DOMICILIARIO. Hoy el
 * origen es la TIENDA, que es fija. El día que alguien trace desde donde va el
 * repartidor, cada metro recorrido sería una clave nueva y las tres capas
 * dejarían de servir para nada: se pagaría una llamada cada veinte segundos.
 */
class TrazoDeLaRuta
{
    /**
     * UN MES EN CACHÉ, Y NO MEDIA HORA.
     *
     * El camino de una tienda a una casa es la misma calle hoy y el mes que
     * viene. Un mes y no para siempre porque las calles sí cambian de vez en
     * cuando —una obra, un sentido invertido— y porque una entrada que nunca
     * vence no se puede corregir sin entrar a mano a borrarla.
     */
    public const MINUTOS_EN_CACHE = 60 * 24 * 30;

    /**
     * Los decimales con los que se construye la clave.
     *
     * Cinco decimales son algo más de un metro: suficiente para que «la misma
     * tienda» y «la misma casa» caigan siempre en la misma entrada aunque el
     * cliente mande la coordenada con más cifras, y poco suficiente para no
     * mezclar dos casas distintas.
     */
    private const DECIMALES_DE_LA_CLAVE = 5;

    /**
     * La ruta de ESTE pedido, trazándola la primera vez.
     *
     * Devuelve `null` cuando no se puede trazar —sin clave de Google, sin
     * coordenadas, o Google no devuelve nada—. Quien llama dibuja entonces la
     * recta entre los dos puntos, que es lo que la app ya hace.
     *
     * NO SE GUARDA UN FALLO. `route_traced_at` se escribe solo cuando hay
     * polilínea de verdad: si se marcara el intento fallido, el pedido se
     * quedaría sin ruta para siempre el día que la clave de Google quede
     * puesta. Es el mismo error que la app evitó en su caché del teléfono.
     */
    public function paraElPedido(OrdersSales $pedido): ?string
    {
        if ($pedido->route_polyline) {
            return $pedido->route_polyline;
        }

        $pedido->loadMissing(['business', 'address']);

        $origen  = $this->punto($pedido->business?->latitude, $pedido->business?->longitude);
        $destino = $this->punto($pedido->address?->latitude, $pedido->address?->longitude);

        if ($origen === null || $destino === null) {
            return null;
        }

        try {
            $polilinea = $this->entre($origen, $destino);
        } catch (RuntimeException $e) {
            Log::warning('No se pudo trazar la ruta del pedido', [
                'order_id' => $pedido->orderSales_id,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }

        if (!$polilinea) {
            return null;
        }

        /*
         * `update` directo y no `save()`: este método se llama desde una cola
         * y desde una petición, y el modelo que recibe puede traer otros
         * cambios a medio hacer que no son asunto de la ruta.
         */
        $pedido->forceFill([
            'route_polyline'  => $polilinea,
            'route_traced_at' => now(),
        ])->save();

        return $polilinea;
    }

    /**
     * El trazo entre dos puntos, con su caché.
     *
     * @param  string  $origen   «lat,lng»
     * @param  string  $destino  «lat,lng»
     * @throws RuntimeException  si Google responde mal
     */
    public function entre(string $origen, string $destino): ?string
    {
        $clave = (string) config('services.google.maps_server_key');

        if ($clave === '') {
            Log::error('No hay GOOGLE_MAPS_SERVER_KEY: no se puede trazar la ruta');

            return null;
        }

        /*
         * La clave se normaliza antes de resumirla: «10.9878,-74.7889» y
         * «10.98780,-74.78890» son el mismo sitio y tenían que caer en la
         * misma entrada. El prefijo lleva versión para poder invalidar todo de
         * golpe el día que haya que cambiar cómo se traza.
         */
        $memoria = 'ruta:v2:' . md5(
            $this->normalizar($origen) . '|' . $this->normalizar($destino),
        );

        return Cache::remember(
            $memoria,
            now()->addMinutes(self::MINUTOS_EN_CACHE),
            function () use ($origen, $destino, $clave) {
                $respuesta = Http::timeout(8)->get(
                    'https://maps.googleapis.com/maps/api/directions/json',
                    [
                        'origin'      => $origen,
                        'destination' => $destino,
                        'key'         => $clave,
                        /* En moto, que es como se reparte acá. */
                        'mode'        => 'driving',
                    ],
                );

                if ($respuesta->failed()) {
                    throw new RuntimeException($respuesta->body());
                }

                return $respuesta->json('routes.0.overview_polyline.points');
            },
        );
    }

    /** «lat,lng», o null si falta alguna de las dos. */
    private function punto($lat, $lng): ?string
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        return ((float) $lat) . ',' . ((float) $lng);
    }

    /** «lat,lng» con los decimales fijos, para que la clave no dependa del formato. */
    private function normalizar(string $punto): string
    {
        [$lat, $lng] = array_map('floatval', explode(',', $punto));

        return number_format($lat, self::DECIMALES_DE_LA_CLAVE, '.', '')
            . ',' . number_format($lng, self::DECIMALES_DE_LA_CLAVE, '.', '');
    }
}
