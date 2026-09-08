<?php

namespace App\Services;

use App\Models\Order\OrdersSales;

/**
 * Las tres reglas para asignarle un pedido a un domiciliario.
 *
 * Estaban dentro de la rama 2 -> 3 de `OrderController::moverPedido`,
 * mezcladas con las respuestas HTTP. Salen porque por esa transicion pasan
 * DOS caminos —el domiciliario aceptando y el tendero despachando— y las
 * reglas tienen que ser las mismas para los dos; y porque asi se pueden
 * probar sin montar una peticion.
 *
 * Sigue el patron que ya usaba `puedeMoverPedido`: devuelve el motivo por el
 * que NO se puede, o null si se puede. Quien llama decide como responderlo.
 */
class ReglasDeDespacho
{
    /** `orderssales.methods_id` del pago contra entrega. */
    private const EFECTIVO = 1;

    /**
     * @return array|null  El impedimento con su `reason` y sus cifras, o null.
     */
    public function impedimento(OrdersSales $order, $domiciliary): ?array
    {
        /*
         * Reglas de asignación. Se validan acá y no en la app porque por
         * esta misma transición pasan los dos caminos: el domiciliario
         * aceptando un pedido y el tendero despachándoselo. Si viviera
         * solo en el cliente, cualquiera de los dos podría saltársela.
         */
        if (!$domiciliary->available) {
            return [
                'message' => 'El domiciliario no está disponible en este momento.',
                'reason'  => 'unavailable',
            ];
        }

        $maxSimultaneos = (int) Ajustes::valor('operacion.entregas_simultaneas');

        $enCurso = OrdersSales::where('domiciliary_id', $domiciliary->domiciliary_id)
            ->confirmados()
            ->where('state', 3)
            ->where('orderSales_id', '!=', $order->orderSales_id)
            ->count();

        if ($enCurso >= $maxSimultaneos) {
            return [
                'message' => "Ya hay {$enCurso} pedidos en curso. Se debe entregar alguno antes de aceptar otro.",
                'reason'  => 'limit_reached',
                'active_orders' => $enCurso,
                'max_active_orders' => $maxSimultaneos,
            ];
        }

        /*
         * CUÁNTO EFECTIVO PUEDE LLEVAR ENCIMA.
         *
         * Sólo cuenta para los pedidos contra entrega: uno ya pagado por la
         * app no le pone un peso más en el bolsillo, y bloquearlo por el
         * saldo sería castigarlo por deber dinero que no tiene que ver.
         *
         * El tope es del NEGOCIO que despacha, aunque el saldo del
         * domiciliario sea global —cobra para varias tiendas—. Es lo
         * correcto: quien decide si le confía otro pedido en efectivo es
         * quien se lo está entregando.
         *
         * Se valida acá y no en la app porque por esta transición pasan los
         * dos caminos: el tendero despachando y el domiciliario tomando el
         * pedido de su lista. En el cliente, cualquiera de los dos se la
         * saltaría.
         */
        $tope = $order->business?->max_courier_cash;

        if ($tope !== null && (int) $order->methods_id === self::EFECTIVO) {
            $encima = app(CustodiaDeEfectivo::class)
                ->saldo($domiciliary->domiciliary_id);

            $quedaria = $encima + (float) $order->total;

            if ($quedaria > (float) $tope) {
                return [
                    'message' => 'Con este pedido pasaría el máximo de efectivo que puede llevar encima. Tiene que consignar antes.',
                    'reason'  => 'cash_limit_reached',
                    'cash_now'   => round($encima, 2),
                    'cash_after' => round($quedaria, 2),
                    'cash_limit' => (float) $tope,
                ];
            }
        }

        return null;
    }
}
