<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\Order\OrdersSales;
use App\Models\Payment\Payment;
use App\Models\Payment\PaymentIntent;
use App\Services\BoldService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    /**
     * Crear intención de pago
     */
    public function createIntent(OrdersSales $order, BoldService $bold): PaymentIntent
    {
        $reference = 'ORD-' . $order->orderSales_id . '-' . Str::upper(Str::random(8));

        $body = [
            "reference_id" => $reference,
            "amount" => [
                "currency" => "COP",
                "total_amount" => (int) $order->total
            ],
            "description" => "Pago orden #{$order->orderSales_id}",

            "customer" => [
                "name" => $order->buyer->user->name ?? "Cliente",
                "phone" => $order->buyer->user->phone ?? "3000000000",
                "email" => $order->buyer->user->email ?? "test@test.com",

                "billing_address" => [
                    "street1" => $order->address->address,
                    "street2" => "",
                    "city" => $order->address->municipality,
                    "postal_code" => "130001", // ⚠️ IMPORTANTE
                    "province" => $order->address->department,
                    "country_code" => "CO",
                    "phone" => $order->buyer->user->phone ?? "3000000000"
                ]
            ]
        ];

        // 👉 AQUÍ VA EL LOG
        \Log::info('BOLD PAYMENT BODY', $body);

        $response = $bold->createIntent($body);

        return PaymentIntent::create([
            'orderSales_id'     => $order->orderSales_id,
            'provider'          => 'bold',
            'bold_reference_id' => $reference,
            'amount'            => $order->total,
            'currency'          => 'COP',
            'status'            => $response['payload']['status'] ?? null,
            'response'          => $response,
        ]);
    }
    /**
     * Crear pago (TARJETA / QR)
     */
    public function createPayment(
        OrdersSales $order,
        PaymentIntent $intent,
        array $payer,
        array $paymentMethod,
        array $products,
        Request $request,
        BoldService $bold
    ): Payment {

        $body = [
            "reference_id" => $intent->bold_reference_id,
            "payer" => $payer,
            "payment_method" => $paymentMethod,
            "amount" => [
                "currency" => "COP",
                "total_amount" => (int) $order->total
            ],
            /* "products" => $products, */
            "metadata" => [
                "key" => "order_id",
                "value" => (string) $order->orderSales_id
            ],
            "device_fingerprint" => [
                "device_type" => "MOBILE",
                "os" => "Android",
                "model" => "ReactNative",
                "browser" => "MobileApp",
                "java_enabled" => false,
                "language" => "es",
                "color_depth" => 24,
                "screen_height" => 800,
                "screen_width" => 400,
                "time_zone_offset" => -300,
            ]
        ];

        $boldResponse = $bold->makePayment($body);

        // Manejo del QR
        $qrPayload = null;
        $qrExpiresAt = null;
        $next = $boldResponse['next_actions'] ?? [];

        $redirectUrl = $next['redirect_url'] ?? null;

        // 🔥 Soporta ambos formatos (nuevo y viejo de Bold)
        $qrPayload = $next['qr_payload']
            ?? ($next['qr']['payload'] ?? null);

        $qrExpiresAt = null;

        // formato nuevo (expires_at en timestamp)
        $qrExpiresAt = null;

        // 🔥 FIX correcto
        if (isset($next['expires_at'])) {
            $qrExpiresAt = now()->addMinutes(10);
        }

        // fallback viejo
        elseif (isset($next['qr']['expires_in'])) {
            $qrExpiresAt = now()->addSeconds(
                (int) $next['qr']['expires_in']
            );
        }

        // Guardamos el intento de pago como "running" / pending
        return Payment::create([
            'orderSales_id' => $order->orderSales_id,
            'methods_id' => $order->methods_id,
            'provider' => 'bold',
            'provider_payment_id' => $boldResponse['transaction_id'] ?? null,
            'amount' => $order->total,
            'subtotal' => $order->total,
            'total' => $order->total,
            'status' => 'running', // estado inicial
            'payment_status' => 0,  // aún no aprobado
            'provider_snapshot' => $boldResponse,
            'redirect_url' => $redirectUrl,
            'qr_payload' => $qrPayload,
            'qr_expires_at' => $qrExpiresAt,
            'payment_date' => now(),
            'state' => 1,
        ]);
    }

    /**
     * Consultar estado del pago
     */
    public function checkStatus(string $reference, BoldService $bold)
    {
        $data = $bold->checkPayment($reference);
        $status = strtoupper($data['status'] ?? '');

        $intent = PaymentIntent::where('bold_reference_id', $reference)->firstOrFail();
        $order  = OrdersSales::findOrFail($intent->orderSales_id);

        // Buscamos el pago existente
        $payment = Payment::where('orderSales_id', $order->orderSales_id)
            ->where('provider_payment_id', $data['transaction_id'] ?? null)
            ->first();

        if ($payment) {
            // Solo actualizamos si estaba en 'running'
            if ($payment->status === 'running') {
                $redirectUrl = $data['next_actions']['redirect_url'] ?? null;
                $qrPayload = $data['next_actions']['qr']['payload'] ?? null;
                $qrExpiresAt = isset($data['next_actions']['qr']['expires_in'])
                    ? now()->addSeconds((int) $data['next_actions']['qr']['expires_in'])
                    : null;

                $payment->update([
                    'payment_status' => $status === 'APPROVED' ? 1 : 0,
                    'status' => strtolower($status),
                    'provider_snapshot' => $data,
                    'redirect_url' => $redirectUrl,
                    'qr_payload' => $qrPayload,
                    'qr_expires_at' => $qrExpiresAt,
                    'payment_date' => now(),
                ]);

                // Actualizamos el estado de la orden según resultado
                $order->update([
                    'payment_state' => $status === 'APPROVED' ? 'paid' : 'pending_online'
                ]);
            }
        }

        return response()->json([
            'payment_status' => $status,
            'order_payment_state' => $order->payment_state,
            'data' => $data
        ]);
    }
}
