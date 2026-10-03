<?php

namespace App\Services;

use App\Models\Order\OrdersSales;
use App\Models\Payment\Payment;
use Illuminate\Support\Facades\Log;

/**
 * DEVOLVER LA PLATA DE UN PEDIDO QUE SE CANCELA.
 *
 * Hasta ahora no existía. `OrderController::cancel` devolvía el inventario y
 * el cupo de fiado —lo que no cuesta dinero— y el cobro no se tocaba: la plata
 * se quedaba en Bold y nadie se enteraba. El cliente tenía que reclamar, y la
 * única forma de resolverlo era que alguien se acordara de entrar al panel.
 *
 * BOLD TIENE DOS CAMINOS Y UNO NO SIRVE PARA TODO:
 *
 *   · ANULAR (`/v1/payment/void`) — el mismo día, antes de las 9 p. m. Es el
 *     bueno: la plata no llega a salir de la cuenta del cliente.
 *   · REEMBOLSAR (`/v1/payment/refund`) — después de esa hora. No es
 *     inmediato: Bold lo revisa y lo aprueba, así que lo que queda es una
 *     SOLICITUD.
 *
 * Y la parte incómoda: LOS DOS SON SOLO PARA TARJETA. Con QR (Bre-B) o PSE,
 * Bold no ofrece ningún endpoint, así que la devolución es a mano desde su
 * panel. Eso no se puede automatizar, pero sí se puede dejar ANOTADO para que
 * no se pierda, que es justo lo que pasaba antes.
 */
class DevolucionDelPedido
{
    /** Pasada esta hora, Bold ya no anula: toca reembolso. */
    private const HORA_LIMITE_PARA_ANULAR = 21;

    /** Los que Bold sabe devolver solo. El resto van a mano. */
    private const AUTOMATICOS = [2];

    public function __construct(private readonly BoldService $bold)
    {
    }

    /**
     * Devuelve lo cobrado de un pedido, si hay algo que devolver.
     *
     * No lanza nunca: una devolución que falla NO puede tumbar la cancelación
     * del pedido. Si Bold no responde, el pago queda en `pendiente` y alguien
     * lo retoma; lo contrario —que el pedido no se cancele porque la pasarela
     * está caída— deja al cliente sin pedido Y sin cancelación.
     *
     * @return string|null El estado en que quedó, o null si no había qué devolver.
     */
    public function devolver(OrdersSales $order, string $motivo): ?string
    {
        $pago = Payment::where('orderSales_id', $order->orderSales_id)
            ->where('payment_status', 1)
            ->latest('payments_id')
            ->first();

        // Sin cobro aprobado no hay nada que devolver: efectivo, fiado, o un
        // pago en línea que nunca llegó a aprobarse.
        if (! $pago) {
            return null;
        }

        // Ya se devolvió antes. Volver a pedirlo sería devolver dos veces.
        if (in_array($pago->refund_status, ['anulada', 'devuelta', 'solicitada'], true)) {
            return $pago->refund_status;
        }

        $pago->refund_reason = $motivo;

        /*
         * QR y PSE: Bold no tiene por dónde. Se marca `manual` y se avisa, que
         * es todo lo que se puede hacer de verdad. Fingir que se devolvió sería
         * peor que decir que falta hacerlo.
         */
        if (! in_array((int) $pago->methods_id, self::AUTOMATICOS, true)) {
            $pago->refund_status = 'manual';
            $pago->save();

            Log::warning('Devolución que hay que hacer A MANO en el panel de Bold', [
                'order_id'       => $order->orderSales_id,
                'payment_id'     => $pago->payments_id,
                'transaction_id' => $pago->provider_payment_id,
                'methods_id'     => $pago->methods_id,
                'monto'          => $pago->total,
            ]);

            return 'manual';
        }

        $transaccion = $pago->provider_payment_id;

        if (! $transaccion) {
            $pago->refund_status = 'pendiente';
            $pago->save();

            Log::error('No se puede devolver: el pago no tiene transacción de Bold', [
                'order_id'   => $order->orderSales_id,
                'payment_id' => $pago->payments_id,
            ]);

            return 'pendiente';
        }

        try {
            if ($this->sePuedeAnular($pago)) {
                $respuesta = $this->bold->anular($transaccion);
                $pago->refund_status = 'anulada';
                $pago->refunded_at = now();
            } else {
                /* La referencia del intento, que es la que Bold conoce. Si no
                   la hubiera, se manda la del pedido antes que no mandar nada. */
                $referencia = \App\Models\Payment\PaymentIntent::where(
                    'orderSales_id',
                    $order->orderSales_id,
                )->latest('id')->value('bold_reference_id')
                    ?? 'ORD-' . $order->orderSales_id;

                $respuesta = $this->bold->devolver($referencia, $transaccion, $motivo);
                // Solicitada, no devuelta: Bold todavía tiene que aprobarla.
                $pago->refund_status = 'solicitada';
            }

            $pago->refund_snapshot = $respuesta;
            $pago->save();

            return $pago->refund_status;
        } catch (\Throwable $e) {
            /*
             * Queda `pendiente` a propósito, no `rechazada`: no sabemos que
             * Bold dijera que no, solo que no pudimos preguntárselo. Una
             * devolución que se da por rechazada sin serlo es plata que nadie
             * vuelve a mirar.
             */
            $pago->refund_status = 'pendiente';
            $pago->save();

            Log::error('No se pudo devolver el cobro', [
                'order_id'   => $order->orderSales_id,
                'payment_id' => $pago->payments_id,
                'excepcion'  => $e->getMessage(),
            ]);

            return 'pendiente';
        }
    }

    /** El mismo día del cobro y antes de las 9 p. m. */
    private function sePuedeAnular(Payment $pago): bool
    {
        $cobrado = $pago->payment_date ?? $pago->created_at;

        if (! $cobrado) {
            return false;
        }

        return $cobrado->isSameDay(now())
            && now()->hour < self::HORA_LIMITE_PARA_ANULAR;
    }

    /**
     * Lo que hay que decirle a la persona, según cómo quedó.
     *
     * El texto importa tanto como la llamada: «se anuló» y «lo estamos
     * gestionando» son dos esperas muy distintas, y quien no sabe en cuál está
     * vuelve a escribir cada día.
     */
    public static function queDecirle(?string $estado, float $monto): ?string
    {
        $plata = '$' . number_format($monto, 0, ',', '.');

        return match ($estado) {
            'anulada' => "Anulamos el cobro de {$plata}. No te van a cobrar nada.",
            'solicitada' => "Pedimos la devolución de {$plata}. Tu banco puede tardar "
                . 'unos días hábiles en reflejarla.',
            'devuelta' => "Te devolvimos {$plata}.",
            'manual', 'pendiente' => "Vamos a devolverte {$plata}. Te escribimos "
                . 'en cuanto esté hecho.',
            default => null,
        };
    }
}
