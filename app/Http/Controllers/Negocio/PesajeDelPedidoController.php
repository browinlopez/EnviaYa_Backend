<?php

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Models\Order\OrdersSales;
use App\Models\Order\OrdersSalesDetail;
use App\Services\ExistenciasDelPedido;
use App\Services\Ajustes;
use App\Support\UnidadDeVenta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * EL TENDERO PESA LO QUE VA EN LA BOLSA.
 *
 * El precio YA ESTÁ FIJADO: el cliente pidió «3.000 de queso» o «500 g de
 * tomate», y en los dos casos lo que aceptó es lo que paga. Lo que se
 * registra acá es cuánto salió de verdad, y sirve para dos cosas distintas:
 * descontar bien el inventario, y dejar constancia de lo que pesó quien lo
 * pesó.
 *
 * Por eso este endpoint NO toca el total. No hay nada que recalcular, ningún
 * cobro que ajustar, ninguna diferencia que devolver. Esa es la decisión que
 * hace esta función pequeña en vez de un proyecto.
 *
 * EL TOPE DE DESVÍO es lo único que limita a quien pone el peso. Sin él, el
 * tendero puede cerrar un pedido de $8.000 con tres kilos de queso de más y
 * el inventario se descuadra sin que nadie lo note; con un pedido por plata,
 * además, el cliente recibiría bastante menos de lo que pagó. Se configura
 * desde el panel (`operacion.desvio_peso_max`) y no es una constante del
 * código: demasiado estricto y no se puede cortar un trozo real, demasiado
 * laxo y no protege nada.
 */
class PesajeDelPedidoController extends Controller
{
    public function __construct(private readonly ExistenciasDelPedido $existencias)
    {
    }

    public function registrar(Request $request)
    {
        $request->validate([
            'order_id'              => 'required|integer',
            'lineas'                => 'required|array|min:1',
            'lineas.*.orderDet_id'  => 'required|integer',
            /*
             * Hasta tres decimales: la báscula de mostrador pesa en gramos y
             * un kilo tiene mil. Más precisión sería inventarla.
             */
            'lineas.*.qty_final'    => 'required|numeric|min:0.001|max:1000',
        ]);

        $businessId = (int) $request->attributes->get('busines_id');
        $usuarioId = (int) ($request->user()?->user_id);

        $tope = (float) Ajustes::valor('operacion.desvio_peso_max');

        try {
            $resultado = DB::transaction(function () use ($request, $businessId, $usuarioId, $tope) {
                /*
                 * Con candado, igual que al mover el estado del pedido: dos
                 * pesajes a la vez sobre el mismo pedido descontarían el
                 * inventario dos veces.
                 */
                $pedido = OrdersSales::where('orderSales_id', (int) $request->input('order_id'))
                    ->where('busines_id', $businessId)
                    ->lockForUpdate()
                    ->first();

                if (!$pedido) {
                    /*
                     * 404 y no 403: responder «prohibido» sobre el número de
                     * otra tienda confirmaría que ese pedido existe.
                     */
                    abort(404, 'Ese pedido no es de tu tienda.');
                }

                /*
                 * PRECONDICIÓN, y de paso la idempotencia ante un reintento de
                 * red: un pedido ya pesado o ya despachado no se vuelve a
                 * pesar. A esas alturas el inventario ya se movió.
                 */
                if ((int) $pedido->weighing_state !== OrdersSales::POR_PESAR) {
                    abort(409, (int) $pedido->weighing_state === OrdersSales::PESADO
                        ? 'Este pedido ya se pesó.'
                        : 'Este pedido no lleva productos al peso.');
                }

                if (!in_array((int) $pedido->state, [1, 2], true)) {
                    abort(409, 'Este pedido ya salió: no se puede pesar.');
                }

                $lineas = OrdersSalesDetail::where('orderSales_id', $pedido->orderSales_id)
                    ->get()
                    ->keyBy('orderDet_id');

                $deltas = [];

                foreach ($request->input('lineas') as $entrada) {
                    $linea = $lineas[(int) $entrada['orderDet_id']] ?? null;

                    if (!$linea || !UnidadDeVenta::esPeso($linea->sale_unit)) {
                        abort(422, 'Una de esas líneas no se vende al peso.');
                    }

                    $pesado = (float) $entrada['qty_final'];
                    $pedida = (float) $linea->qty_requested;

                    if ($pedida > 0 && $tope > 0) {
                        $desvio = abs($pesado - $pedida) / $pedida;

                        if ($desvio > $tope) {
                            abort(422, sprintf(
                                'Pesaste %s donde el cliente pidió %s. Si no hay suficiente, llámalo o cancela el pedido.',
                                UnidadDeVenta::enPalabras($pesado, $linea->sale_unit),
                                UnidadDeVenta::enPalabras($pedida, $linea->sale_unit),
                            ));
                        }
                    }

                    // Lo que falta por descontar: ya se resto lo pedido al crear.
                    $deltas[(int) $linea->product_id] =
                        ($deltas[(int) $linea->product_id] ?? 0) + ($pesado - $pedida);

                    $linea->qty_final = $pesado;
                    $linea->weighed_at = now();
                    $linea->weighed_by = $usuarioId ?: null;
                    $linea->save();
                }

                /*
                 * Las que nadie peso se quedan en lo pedido: el tendero puede
                 * pesar lo que le falte en otro momento, y el inventario ya
                 * cuadra con lo pedido para esas.
                 */
                $this->existencias->ajustarPorPesaje($pedido, $deltas);

                $pedido->weighing_state = OrdersSales::PESADO;
                $pedido->weighed_at = now();
                $pedido->save();

                return $pedido;
            });
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        }

        /*
         * El aviso va FUERA de la transaccion: si el push falla, el pesaje ya
         * esta guardado y lo peor que pasa es que el cliente se entere al
         * abrir la app. Al reves, un fallo del aviso desharia un pesaje que ya
         * movio el inventario.
         */
        try {
            broadcast(new \App\Events\OrderStatusUpdated($resultado->fresh()));
        } catch (\Throwable $e) {
            // El estado del pedido no cambió; el aviso es una cortesía.
        }

        return response()->json([
            'message' => 'Pesaje guardado.',
            'data'    => $resultado->fresh()->toApi(),
        ]);
    }
}
