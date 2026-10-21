<?php

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Controller;
use App\Models\Order\OrdersSales;
use App\Services\TrazoDeLaRuta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
 *
 * CON `order_id` ES GRATIS. El pedido guarda su ruta desde que se despacha
 * (ver `TrazoDeLaRuta`), así que pedirla con el número del pedido no llega ni
 * a tocar la caché. Sin él se sigue atendiendo por origen y destino, que es lo
 * que manda la app que ya está publicada en las tiendas y no se puede cambiar
 * a voluntad.
 */
class RutaDelPedidoController extends Controller
{
    public function __construct(private readonly TrazoDeLaRuta $rutas)
    {
    }

    public function __invoke(Request $request)
    {
        $datos = $request->validate([
            'order_id' => 'sometimes|nullable|integer',
            'origen'   => ['required_without:order_id', 'string', 'regex:/^-?\d+(\.\d+)?,-?\d+(\.\d+)?$/'],
            'destino'  => ['required_without:order_id', 'string', 'regex:/^-?\d+(\.\d+)?,-?\d+(\.\d+)?$/'],
        ]);

        if (!empty($datos['order_id'])) {
            return $this->delPedido($request, (int) $datos['order_id']);
        }

        try {
            $polilinea = $this->rutas->entre($datos['origen'], $datos['destino']);
        } catch (RuntimeException $e) {
            return $this->sinTrazado();
        }

        return $polilinea === null
            ? $this->sinTrazado()
            : response()->json(['polilinea' => $polilinea]);
    }

    /**
     * La ruta guardada del pedido.
     *
     * Se comprueba que quien pregunta tenga algo que ver con él. No es
     * paranoia: la ruta dice de qué tienda a qué casa va un pedido, y los
     * números de pedido son correlativos, así que sin esto se podría ir
     * sacando la dirección aproximada de cada entrega de la plataforma.
     */
    private function delPedido(Request $request, int $orderId)
    {
        $pedido = OrdersSales::with(['business', 'address'])->find($orderId);

        if (!$pedido || !$this->puedeVerlo($request, $pedido)) {
            // 404 y no 403: decir «prohibido» sobre el número de otro
            // confirmaría que ese pedido existe.
            return response()->json(['message' => 'Ese pedido no es tuyo.'], 404);
        }

        $polilinea = $this->rutas->paraElPedido($pedido);

        return $polilinea === null
            ? $this->sinTrazado()
            : response()->json(['polilinea' => $polilinea]);
    }

    /** El comprador, su domiciliario, la tienda que lo vende y el equipo. */
    private function puedeVerlo(Request $request, OrdersSales $pedido): bool
    {
        $usuario = $request->user();

        if (!$usuario) {
            return false;
        }

        if ((int) $usuario->rol === 4) {
            return true;
        }

        $suyo = (int) $usuario->user_id;

        if ((int) ($pedido->buyer?->user_id) === $suyo) {
            return true;
        }

        if ($pedido->domiciliary_id && (int) ($pedido->domiciliary?->user_id) === $suyo) {
            return true;
        }

        return DB::table('owner_busines as ob')
            ->join('owner as o', 'o.owner_id', '=', 'ob.owner_id')
            ->where('ob.busines_id', $pedido->busines_id)
            ->where('o.user_id', $suyo)
            ->exists();
    }

    /**
     * 503 y no 500: no es que la petición esté mal, es que falta
     * configuración del servidor o Google no respondió. La app lo trata como
     * «sin ruta» y pinta la línea recta, que es lo que hacía antes de que esto
     * existiera.
     */
    private function sinTrazado()
    {
        return response()->json([
            'message' => 'El trazado de rutas no está disponible.',
        ], 503);
    }
}
