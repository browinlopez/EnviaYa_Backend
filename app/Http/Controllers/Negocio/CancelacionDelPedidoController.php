<?php

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Models\Order\OrdersSales;
use App\Services\CancelacionDelPedido;
use Illuminate\Http\Request;

/**
 * LA TIENDA CANCELA UN PEDIDO QUE NO PUEDE PREPARAR.
 *
 * No había por dónde. El único camino al estado 5 era `PUT /orders/{id}/cancel`,
 * que exige ser el comprador y que el pedido no haya sido aceptado todavía. O
 * sea: en el momento en que el tendero se da cuenta de que no tiene el
 * producto —después de aceptar, que es cuando va a buscarlo al estante— ya no
 * había manera de cancelar. Y como el comprador tampoco puede a esas alturas,
 * el pedido se quedaba vivo: restando del inventario, con el cupo de fiado
 * comprometido, y con el cobro en Bold sin devolver.
 *
 * EL MOTIVO ES OBLIGATORIO ACÁ, y no lo es cuando cancela el comprador. La
 * diferencia no es un capricho: cuando cancela la tienda, el cliente no hizo
 * nada y su pedido desaparece, así que el motivo es lo único que le explica lo
 * que pasó. Se le manda tal cual en el aviso.
 *
 * HASTA EL ESTADO 2 Y NO MÁS. En cuanto el pedido se despacha hay alguien en
 * la calle con la bolsa encima, y quién le paga ese viaje es una decisión que
 * nadie ha tomado. Un camino que deje a un domiciliario a medio reparto sin
 * saber qué se le debe es peor que no tener camino.
 */
class CancelacionDelPedidoController extends Controller
{
    /** Los estados desde los que la tienda puede deshacer un pedido. */
    private const CANCELABLES = [1, 2];

    public function __construct(private readonly CancelacionDelPedido $cancelaciones)
    {
    }

    public function cancelar(Request $request, $id)
    {
        $datos = $request->validate([
            'motivo' => 'required|string|min:3|max:500',
        ]);

        $businessId = (int) $request->attributes->get('busines_id');

        /*
         * El pedido se busca CON la tienda de la sesión en la condición. El
         * middleware `negocio` ya resolvió cuál es y pisó lo que mandara el
         * cliente, así que acá no hay forma de cancelar el pedido de otro.
         */
        $order = OrdersSales::with('buyer')
            ->where('orderSales_id', $id)
            ->where('busines_id', $businessId)
            ->first();

        if (!$order) {
            /*
             * 404 y no 403: responder «prohibido» sobre el número de otra
             * tienda confirmaría que ese pedido existe. Los identificadores
             * son correlativos.
             */
            return response()->json(['message' => 'Ese pedido no es de tu tienda.'], 404);
        }

        if ((int) $order->state === CancelacionDelPedido::CANCELADO) {
            return response()->json([
                'message' => 'Este pedido ya estaba cancelado.',
                'reason'  => 'ya_cancelado',
            ], 409);
        }

        if (!in_array((int) $order->state, self::CANCELABLES, true)) {
            return response()->json([
                'message' => 'Este pedido ya salió a reparto: háblalo con el domiciliario '
                    . 'por el chat del pedido.',
                'reason'  => 'ya_despachado',
            ], 422);
        }

        $hecho = $this->cancelaciones->cancelar(
            $order,
            (int) $request->user()->user_id,
            CancelacionDelPedido::TIENDA,
            $datos['motivo'],
        );

        if ($hecho['ya_estaba']) {
            return response()->json([
                'message' => 'Este pedido ya estaba cancelado.',
                'reason'  => 'ya_cancelado',
            ], 409);
        }

        return response()->json([
            'message' => 'Pedido cancelado. Le avisamos al cliente con el motivo.'
                . ($hecho['aviso'] ? ' ' . $hecho['aviso'] : ''),
            'order'      => $order->fresh()->toApi(),
            'devolucion' => $hecho['devolucion'],
            'motivo'     => $hecho['motivo'],
        ]);
    }
}
