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
    private const REJECTED_TYPES = ['SALE_REJECTED', 'VOID_APPROVED', 'VOID_REJECTED'];

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

        $secret = config('services.bold.webhook_secret', '');

        // Sin secreto configurado la firma HMAC sería trivial de falsificar:
        // mejor rechazar todo hasta que BOLD_WEBHOOK_SECRET esté definido.
        if ($secret === '') {
            Log::error('Bold webhook: BOLD_WEBHOOK_SECRET no está configurado');

            return false;
        }

        $encoded = base64_encode($rawBody);
        $expected = hash_hmac('sha256', $encoded, $secret);

        return hash_equals($expected, $signature);
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
