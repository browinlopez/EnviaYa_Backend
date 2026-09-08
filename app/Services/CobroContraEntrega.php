<?php

namespace App\Services;

use App\Models\Payment\Payment;
use App\Models\Order\OrdersSales;
use Illuminate\Support\Facades\DB;

/**
 * El cobro de un pedido que se paga en efectivo, al entregarlo.
 *
 * Estaba dentro de la rama 3 -> 4 de `OrderController::moverPedido`. Sale
 * porque es lo unico de ese metodo que mueve dinero, y porque el dia que la
 * entrega se declare desde otro sitio —el panel, una correccion manual— el
 * apunte del efectivo tiene que ser el mismo. Duplicarlo seria volver al
 * agujero que este codigo cierra: un pago aprobado sin nadie responsable de
 * la plata.
 */
class CobroContraEntrega
{
    /** Valor de `orderssales.methods_id` para el pago contra entrega. */
    private const EFECTIVO = 1;

    public function __construct(private readonly CustodiaDeEfectivo $custodia)
    {
    }

    /**
     * Registra el pago y el recaudo si el pedido era en efectivo.
     *
     * No hace nada —y no es un error— cuando se pago en linea: ahi el cobro
     * lo confirmo la pasarela mucho antes de llegar a la puerta.
     */
    public function registrar(OrdersSales $order, ?int $porQuien = null): void
    {
        if ((int) $order->methods_id !== self::EFECTIVO) {
            return;
        }

            $valorPromocion = 0; // pendiente: descuentos y promociones

            /*
             * El pago, el apunte del efectivo y el estado del pedido, o
             * ninguno de los tres.
             *
             * Sin la transacción, un fallo al apuntar el recaudo dejaría un
             * pago registrado y aprobado sin nadie responsable del dinero
             * —que es exactamente el agujero que este cambio viene a
             * cerrar—.
             */
            DB::transaction(function () use ($order, $porQuien, $valorPromocion) {
            Payment::create([
                'orderSales_id' => $order->orderSales_id,
                'methods_id' => $order->methods_id,
                'forms_id' => $order->forms_id,
                'amount' => $order->total,
                'subtotal' => $order->subtotal,
                'total' => $order->total - $valorPromocion,
                'domicilio' => $order->domicilio,
                'domiciliary_fee' => $order->domiciliary_fee,
                'valor_promocion' => $valorPromocion,
                // `status` es NOT NULL y sin default: no enviarlo hacía
                // fallar el insert, así que los pedidos en efectivo nunca
                // llegaron a registrar pago (y el domiciliario no cobraba).
                'provider' => 'cash',
                'status' => 'approved',
                'payment_status' => 1, // pagado
                'payment_date' => now(),
                'state' => 1 // activo
            ]);

            // El pago quedaba registrado y aprobado, pero la orden seguía
            // diciendo 'pending' para siempre: nadie sincronizaba este
            // campo al cobrar en efectivo. Resultado: pedidos entregados y
            // cobrados que en los tableros aparecían como pendientes de
            // pago, contradiciendo a la tabla de pagos.
            $order->payment_state = 'paid';

            /*
             * Y SE APUNTA QUIÉN TIENE ESE DINERO.
             *
             * Hasta ahora acá terminaba todo: se creaba un pago
             * `approved` y el pedido quedaba `paid`, como si la plata
             * hubiera llegado a la plataforma. No había llegado a ninguna
             * parte — estaba en el bolsillo del domiciliario, sin un solo
             * registro—, y la liquidación encima le PAGABA su comisión sin
             * COBRARLE lo recaudado.
             *
             * Recauda el total del pedido, no su comisión: cobra 32.000 y
             * gana 500. El resto lo debe.
             */
            $this->custodia->registrarRecaudo($order, $porQuien);

                $order->save();
            });
    }
}
