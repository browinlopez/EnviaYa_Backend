<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\Order\OrdersSales;
use App\Models\Payment\Payment;
use App\Models\Payment\PaymentIntent;
use App\Services\BoldService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

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
                "total_amount" => $order->total
            ],
            "description" => "Pago orden #{$order->orderSales_id}"
        ];

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

            // 🔥 PAYER COMPLETO (OBLIGATORIO EN API PREVIA)
            "payer" => $payer,

            "payment_method" => $paymentMethod,
            "products" => $products,

            "metadata" => [
                "key" => "order_id",
                "value" => (string) $order->orderSales_id
            ],

            "device_fingerprint" => [
                "device_type" => "WEB",
                "ip" => $request->ip()
            ]
        ];

        // 🔍 Útil para debug
        // \Log::info('BOLD PAYMENT BODY', $body);

        $boldResponse = $bold->makePayment($body);

        return Payment::create([
            'orderSales_id' => $order->orderSales_id,
            'methods_id' => $order->methods_id,
            'provider' => 'bold',
            'provider_payment_id' => $boldResponse['transaction_id'] ?? null,
            'amount' => $order->total,
            'subtotal' => $order->total,
            'total' => $order->total,
            'status' => strtolower($boldResponse['status'] ?? 'pending'),
            'payment_status' => strtoupper($boldResponse['status'] ?? '') === 'APPROVED' ? 1 : 0,
            'provider_snapshot' => $boldResponse,
            'redirect_url' => $boldResponse['next_actions']['redirect_url'] ?? null,
            'qr_payload' => $boldResponse['next_actions']['qr_payload'] ?? null,
            'qr_expires_at' => isset($boldResponse['next_actions']['expires_at'])
                ? Carbon::createFromTimestampMs($boldResponse['next_actions']['expires_at'])
                : null,
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

        if ($status === 'APPROVED') {
            Payment::firstOrCreate(
                [
                    'orderSales_id' => $order->orderSales_id,
                    'provider_payment_id' => $data['transaction_id'] ?? null,
                ],
                [
                    'methods_id' => $order->methods_id,
                    'provider' => 'bold',
                    'amount' => $order->total,
                    'subtotal' => $order->total,
                    'total' => $order->total,
                    'payment_status' => 1,
                    'status' => 'approved',
                    'provider_snapshot' => $data,
                    'payment_date' => now(),
                    'state' => 1,
                ]
            );

            $order->update(['payment_state' => 'paid']);
        }

        return response()->json([
            'payment_status' => $status,
            'order_payment_state' => $order->payment_state,
            'data' => $data
        ]);
    }
}
