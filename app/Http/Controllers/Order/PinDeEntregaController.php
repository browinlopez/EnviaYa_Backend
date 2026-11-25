<?php

declare(strict_types=1);

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Order\OrdersSales;
use App\Services\NovedadesDeEntrega;
use App\Services\PinDeEntrega;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * QUIÉN VE EL PIN, Y LA DEVOLUCIÓN.
 *
 * El PIN está oculto en el modelo (`OrdersSales::$hidden`): no sale en ningún
 * JSON de pedido, porque el domiciliario los recibe todos. Lo pueden ver:
 *
 *  · el comprador, que es quien se lo dice al domiciliario;
 *  · el negocio, para dárselo al domiciliario si el cliente no lo encuentra
 *    o si se agotaron los intentos.
 */
class PinDeEntregaController extends Controller
{
    public function delComprador(Request $request, int $id)
    {
        $comprador = Buyer::where('user_id', $request->user()->user_id)->first();
        $pedido = OrdersSales::find($id);

        if (! $comprador || ! $pedido || (int) $pedido->buyer_id !== (int) $comprador->buyer_id) {
            return response()->json(['message' => 'Ese pedido no es tuyo.'], 404);
        }

        return response()->json(['data' => $this->datos($pedido, paraElCliente: true)]);
    }

    public function delNegocio(Request $request, int $id)
    {
        $pedido = $this->delMio($request, $id);

        if (! $pedido) {
            return response()->json(['message' => 'Ese pedido no es de tu negocio.'], 404);
        }

        return response()->json(['data' => $this->datos($pedido)]);
    }

    /** El negocio confirma que le devolvieron el producto y la plata. */
    public function recibirDevolucion(Request $request, int $id, NovedadesDeEntrega $novedades)
    {
        $pedido = $this->delMio($request, $id);

        if (! $pedido) {
            return response()->json(['message' => 'Ese pedido no es de tu negocio.'], 404);
        }

        try {
            $novedades->recibirDevolucion($pedido, (int) $request->user()->user_id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Listo. El pedido quedó cancelado y los productos volvieron al inventario.']);
    }

    private function delMio(Request $request, int $id): ?OrdersSales
    {
        $negocio = (int) $request->attributes->get('busines_id');

        return OrdersSales::where('orderSales_id', $id)->where('busines_id', $negocio)->first();
    }

    private function datos(OrdersSales $pedido, bool $paraElCliente = false): array
    {
        $enCamino = (int) $pedido->state === OrdersSales::EN_CAMINO;

        /*
         * EL CÓDIGO PARA RECOGER SOLO LO VE EL CLIENTE. Si la tienda lo
         * viera, pedirlo en el mostrador no probaría nada.
         */
        $paraRecoger = $paraElCliente
            && $pedido->pickup
            && in_array((int) $pedido->state, [OrdersSales::NUEVO, OrdersSales::PREPARANDO], true);

        return [
            'pin' => ($enCamino || $paraRecoger) ? $pedido->pin_entrega : null,
            'para_recoger' => $paraRecoger,
            'bloqueado' => $enCamino && (int) $pedido->pin_intentos >= PinDeEntrega::INTENTOS,
            'llego_at' => $pedido->llego_at,
        ];
    }
}
