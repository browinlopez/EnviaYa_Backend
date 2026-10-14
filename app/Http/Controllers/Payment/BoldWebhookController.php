<?php

namespace App\Http\Controllers\Payment;

use App\Events\PaymentStatusUpdated;
use App\Http\Controllers\Controller;
use App\Models\Order\OrdersSales;
use App\Services\ConfirmacionDePago;
use App\Models\Payment\Payment;
use App\Models\Payment\PaymentEvent;
use App\Models\Payment\PaymentIntent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BoldWebhookController extends Controller
{
    /**
     * Tipos de evento de Bold que aprueban o rechazan la venta.
     * https://developers.bold.co/webhook
     */
    private const APPROVED_TYPES = ['SALE_APPROVED'];

    /*
     * `VOID_REJECTED` NO VA ACÁ, Y ESTABA.
     *
     * Son dos cosas opuestas que se leen parecido:
     *
     *   VOID_APPROVED → la anulación se hizo. La venta se deshizo.
     *                   El pedido pierde el pago. Correcto.
     *   VOID_REJECTED → la anulación FALLÓ. O sea que la venta
     *                   SIGUE APROBADA y la plata sigue cobrada.
     *
     * Con el segundo acá dentro pasaba esto: se intentaba anular un cobro,
     * Bold respondía que no se pudo, y el sistema le quitaba el pago a un
     * pedido que estaba pagado de verdad. El cliente con la plata debitada y
     * el pedido marcado como rechazado.
     *
     * Ahora cae en la rama informativa de `applyStatus`: se guarda el evento
     * —queda el rastro de que la anulación se intentó y no salió— y el estado
     * del pedido no se toca, que es justo lo que corresponde.
     *
     * Los cuatro tipos que existen están en https://developers.bold.co/webhook
     */
    private const REJECTED_TYPES = ['SALE_REJECTED', 'VOID_APPROVED'];

    public function handle(Request $request)
    {
        $rawBody = $request->getContent();

        if (!$this->hasValidSignature($request, $rawBody)) {
            Log::warning('Bold webhook: firma inválida', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Firma inválida'], 400);
        }

        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            return response()->json(['message' => 'Payload inválido'], 400);
        }

        $notificationId = $payload['id'] ?? null;
        $type = $payload['type'] ?? null;
        $transactionId = $payload['subject'] ?? ($payload['data']['payment_id'] ?? null);
        $reference = $payload['data']['metadata']['reference'] ?? null;

        // Idempotencia: Bold puede reenviar la misma notificación varias veces.
        if ($notificationId && PaymentEvent::where('payload->id', $notificationId)->exists()) {
            return response()->json(['ok' => true, 'duplicated' => true]);
        }

        PaymentEvent::create([
            'provider' => 'bold',
            'event_type' => $type ?? 'unknown',
            'reference_id' => $reference ?? $transactionId ?? 'unknown',
            'payload' => $payload,
            'received_at' => now(),
        ]);

        $payment = $this->resolvePayment($transactionId, $reference);

        if (!$payment) {
            Log::warning('Bold webhook: no se encontró el pago', [
                'transaction_id' => $transactionId,
                'reference' => $reference,
            ]);

            // Ya quedó registrado en payment_events; respondemos 200 para que
            // Bold no reintente algo que de todas formas no vamos a poder casar.
            return response()->json(['ok' => true, 'matched' => false]);
        }

        $this->applyStatus($payment, $type, $payload);

        return response()->json(['ok' => true]);
    }

    private function hasValidSignature(Request $request, string $rawBody): bool
    {
        $signature = $request->header('x-bold-signature');

        if (!$signature) {
            return false;
        }

        $secret = (string) config('services.bold.webhook_secret', '');
        $enPruebas = (string) config('services.bold.entorno') !== 'produccion';

        /*
         * EN PRODUCCIÓN, SIN LLAVE NO PASA NADA.
         *
         * Una firma HMAC con secreto vacío la puede calcular cualquiera, y
         * esta ruta es la que marca pedidos como pagados. Mejor rechazar todo
         * —y que se note— que aceptar cobros que nadie firmó.
         */
        if (!$enPruebas && $secret === '') {
            Log::error('Bold webhook: falta la llave secreta de Bold en producción; se rechaza todo');

            return false;
        }

        $encoded = base64_encode($rawBody);

        /*
         * EN PRUEBAS BOLD FIRMA CON CLAVE VACÍA.
         *
         * Lo dice su documentación: «En modo pruebas la firma usa una clave
         * vacía» (https://developers.bold.co/webhook). No es un descuido: en
         * sandbox no hay plata, así que no hace falta repartir secretos para
         * que cada quien ensaye su integración.
         *
         * Esto antes no se contemplaba, y la consecuencia era que el botón
         * «Probar el webhook» del panel daba 400 SIEMPRE: nosotros firmábamos
         * con nuestra llave secreta y Bold venía firmado con nada. O sea que
         * el webhook era imposible de ensayar —y es justo la pieza que decide
         * si un pedido pagado aparece o se queda escondido para siempre.
         *
         * Se admiten las dos llaves en pruebas, la vacía y la nuestra, porque
         * todavía no está claro cuál firma los pagos de la API de pagos en
         * línea: la página de Bold solo nombra las llaves de Botón de pagos y
         * de API Datáfono, y la de pagos en línea no aparece. Mientras Bold lo
         * aclare, el ensayo funciona venga firmado con cualquiera de las dos.
         */
        $llaves = $enPruebas ? array_unique(['', $secret]) : [$secret];

        foreach ($llaves as $llave) {
            if (hash_equals(hash_hmac('sha256', $encoded, $llave), $signature)) {
                return true;
            }
        }

        return false;
    }

    private function resolvePayment(?string $transactionId, ?string $reference): ?Payment
    {
        if ($transactionId) {
            $payment = Payment::where('provider', 'bold')
                ->where('provider_payment_id', $transactionId)
                ->first();

            if ($payment) {
                return $payment;
            }
        }

        if ($reference) {
            $intent = PaymentIntent::where('bold_reference_id', $reference)->first();

            if ($intent) {
                return Payment::where('orderSales_id', $intent->orderSales_id)
                    ->where('provider', 'bold')
                    ->first();
            }
        }

        return null;
    }

    private function applyStatus(Payment $payment, ?string $type, array $payload): void
    {
        $isApproved = in_array($type, self::APPROVED_TYPES, true);
        $isRejected = in_array($type, self::REJECTED_TYPES, true);

        if (!$isApproved && !$isRejected) {
            // Evento informativo que no cambia el resultado final del pago.
            $payment->update(['provider_snapshot' => $payload]);

            return;
        }

        $payment->update([
            'payment_status' => $isApproved ? 1 : 0,
            'status' => strtolower($type),
            'provider_snapshot' => $payload,
            'payment_date' => now(),
        ]);

        $order = OrdersSales::find($payment->orderSales_id);

        if ($order) {
            if ($isApproved) {
                /*
                 * Acá es donde el pedido nace cuando la persona ya cerró la app.
                 *
                 * Sin esto, un cobro que se resuelve tarde dejaba el pedido
                 * escondido para siempre: la tienda nunca se enteraba y el
                 * cliente había pagado.
                 */
                ConfirmacionDePago::confirmar($order);
            } else {
                $order->update([
                    'payment_state' => OrdersSales::PAGO_RECHAZADO,
                ]);
            }
        }

        /*
         * EL ANUNCIO VA APARTE Y TOLERA EL FALLO.
         *
         * Sin esto, con Reverb caido la llamada reventaba y Bold recibia un
         * 500 por un cobro que ya se habia aplicado: el pedido quedaba pagado
         * en la base y la pasarela lo daba por fallido, reintentando algo que
         * ya estaba hecho.
         *
         * Lo que no puede perderse es el PAGO, y eso ya esta guardado unas
         * lineas arriba. Perder el aviso en vivo solo cuesta que la pantalla
         * de la tienda tarde en enterarse, y la app recarga por su cuenta.
         *
         * Mismo criterio que `Avisos::para`, que ya lo hacia asi.
         */
        try {
            PaymentStatusUpdated::dispatch($payment->fresh());
        } catch (\Throwable $e) {
            Log::warning('Bold webhook: no se pudo anunciar el cambio de pago', [
                'payment_id' => $payment->getKey(),
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
