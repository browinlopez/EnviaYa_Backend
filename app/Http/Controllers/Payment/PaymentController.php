<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\Order\OrdersSales;
use App\Models\Payment\Payment;
use App\Models\Payment\PaymentIntent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    private $boldApiUrl;
    private $boldApiKey;

    public function __construct()
    {
        $this->boldApiUrl = config('services.bold.base_url');
        $this->boldApiKey  = config('services.bold.api_key');
    }

    private function boldHeaders()
    {
        return [
            'Authorization' => 'x-api-key ' . $this->boldApiKey,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Crear intención de pago con Bold (POST /v1/payment-intent)
     */
    public function createIntent(Request $request)
    {
        $request->validate([
            'orderSales_id' => 'required|exists:orderssales,orderSales_id'
        ]);

        $order = OrdersSales::findOrFail($request->orderSales_id);

        if ($order->methods_id == 1) {
            return response()->json([
                'message' => 'Pago en efectivo no requiere intención'
            ], 422);
        }

        $reference = 'ORD-' . $order->orderSales_id . '-' . Str::upper(Str::random(8));

        $body = [
            "reference_id" => $reference,
            "amount" => [
                "currency" => "COP",
                "total_amount" => $order->total
            ],
            "description" => "Pago orden #{$order->orderSales_id}"
        ];

        $response = Http::withHeaders($this->boldHeaders())
            ->post("{$this->boldApiUrl}/v1/payment-intent", $body);

        if ($response->failed()) {
            return response()->json([
                'message' => 'Error al crear intención de pago',
                'error'   => $response->body()
            ], 500);
        }

        $data = $response->json();

        $status = $data['payload']['status'] ?? ($data['status'] ?? null);

        $intent = PaymentIntent::create([
            'orderSales_id'     => $order->orderSales_id,
            'provider'          => 'bold',
            'bold_reference_id' => $reference,
            'amount'            => $order->total,
            'currency'          => 'COP',
            'status'            => $status,
            'response'          => $data,
        ]);

        return response()->json([
            'message' => 'Intención creada',
            'intent'  => $data,
        ]);
    }

    /**
     * Ejecutar pago con Bold (POST /v1/payment)
     */
    public function makePayment(Request $request)
    {
        $request->validate([
            'orderSales_id'        => 'required|integer|exists:orderssales,orderSales_id',
            'reference_id'         => 'required|string',
            'payer'                => 'required|array',
            'payment_method'       => 'required|array',
            'payment_method.name'  => 'required|string',
        ]);

        $order = OrdersSales::findOrFail($request->orderSales_id);

        // Body EXACTO que espera Bold
        $body = [
            'reference_id'     => $request->reference_id,
            'metadata'         => $request->metadata ?? [
                'key' => 'order_id',
                'value' => (string) $request->orderSales_id
            ],
            'payer'            => [
                'person_type'     => $request->payer['person_type'],
                'name'            => $request->payer['name'],
                'phone'           => $request->payer['phone'],
                'email'           => $request->payer['email'],
                'document_type'   => $request->payer['document_type'],
                'document_number' => $request->payer['document_number'],
                'billing_address' => $request->payer['billing_address']
            ],
            'payment_method'   => [
                'name'             => $request->payment_method['name'],
                'card_number'      => $request->payment_method['card_number'],
                'cardholder_name'  => $request->payment_method['cardholder_name'],
                'expiration_month' => $request->payment_method['expiration_month'],
                'expiration_year'  => $request->payment_method['expiration_year'],
                'installments'     => intval($request->payment_method['installments']),
                'cvc'              => $request->payment_method['cvc']
            ],
            'device_fingerprint' => $request->device_fingerprint ?? [
                'device_type'        => 'WEB',
                'os'                 => '',
                'model'              => '',
                'browser'            => '',
                'java_enabled'       => false,
                'language'           => 'es',
                'color_depth'        => 24,
                'screen_height'      => 1080,
                'screen_width'       => 1920,
                'time_zone_offset'   => 0
            ]
        ];

        $response = Http::withHeaders($this->boldHeaders())
            ->post("{$this->boldApiUrl}/v1/payment", $body);

        if ($response->failed()) {
            return response()->json([
                'message' => 'Error al intentar el pago en Bold',
                'error'   => $response->json()
            ], 422);
        }

        $data = $response->json();

        // Guardar intento de pago (SIEMPRE)
        $payment = Payment::create([
            'orderSales_id'       => $order->orderSales_id,
            'methods_id'          => 2,
            'provider'            => 'bold',
            'provider_payment_id' => $data['transaction_id'] ?? null,
            'amount'              => $order->total,
            'subtotal'            => $order->total,
            'total'               => $order->total,
            'payment_status'      => $data['status'] === 'APPROVED' ? 1 : 0,
            'status'              => strtolower($data['status'] ?? 'unknown'),
            'provider_snapshot'   => $data,
            'payment_date'        => now(),
            'state'               => 1,
        ]);

        // ✅ SOLO si Bold aprobó
        if (!empty($data['status']) && $data['status'] === 'APPROVED') {
            $order->update([
                'payment_state' => 'paid',
            ]);
        }

        return response()->json([
            'message' => 'Pago procesado con Bold',
            'payment' => $payment,
            'bold_response' => $data
        ]);
    }

    /**
     * Consultar estado del pago (GET /v1/payment/{reference_id})
     */
    public function checkStatus($reference)
    {
        $response = Http::withHeaders($this->boldHeaders())
            ->get("{$this->boldApiUrl}/v1/payment/{$reference}");

        if ($response->failed()) {
            return response()->json([
                'message' => 'Error consultando estado del pago',
                'error'   => $response->body()
            ], 500);
        }

        return response()->json($response->json());
    }
}
