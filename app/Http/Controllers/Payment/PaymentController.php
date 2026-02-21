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
            'orderSales_id' => 'required|integer|exists:orderssales,orderSales_id',
            'reference_id' => 'required|string',
            'payer' => 'required|array',
            'payment_method' => 'required|array',
            'payment_method.name' => 'required|string',
            'products' => 'required|array|min:1',
        ]);

        $order = OrdersSales::findOrFail($request->orderSales_id);

        // Normalizar payer y productos
        $payer = $request->payer;
        $products = array_map(function ($item) {
            return [
                'product_id' => $item['product_id'],
                'amount' => intval($item['amount']),
                'unit_price' => floatval($item['unit_price']),
            ];
        }, $request->products);

        // Payment method
        $paymentMethod = [
            'name' => $request->payment_method['name'],
            'installments' => intval($request->payment_method['installments'] ?? 1),
        ];

        if ($paymentMethod['name'] === 'CREDIT_CARD') {
            $paymentMethod = array_merge($paymentMethod, [
                'card_number' => preg_replace('/\D/', '', $request->payment_method['card_number']),
                'cardholder_name' => $request->payment_method['cardholder_name'],
                'expiration_month' => $request->payment_method['expiration_month'],
                'expiration_year' => $request->payment_method['expiration_year'],
                'cvc' => $request->payment_method['cvc'],
            ]);
        }

        $deviceFingerprint = $request->device_fingerprint ?? [
            'device_type' => 'WEB',
            'ip' => $request->ip(),
        ];

        $body = [
            'reference_id' => $request->reference_id,
            'metadata' => $request->metadata ?? ['key' => 'order_id', 'value' => (string)$order->orderSales_id],
            'payer' => $payer,
            'products' => $products,
            'payment_method' => $paymentMethod,
            'device_fingerprint' => $deviceFingerprint,
        ];

        $response = Http::withHeaders($this->boldHeaders())
            ->post("{$this->boldApiUrl}/v1/payment", $body);

        if ($response->failed()) {
            return response()->json([
                'message' => 'Error al intentar el pago en Bold',
                'error' => $response->json()
            ], 422);
        }

        $data = $response->json()['payload'] ?? $response->json();

        // Guardar Payment con PSE o QR
        $payment = Payment::create([
            'orderSales_id' => $order->orderSales_id,
            'methods_id' => $request->methods_id,
            'provider' => 'bold',
            'provider_payment_id' => $data['transaction_id'] ?? null,
            'amount' => $order->total,
            'subtotal' => $order->total,
            'total' => $order->total,
            'payment_status' => strtoupper($data['status'] ?? '') === 'APPROVED' ? 1 : 0,
            'status' => strtolower($data['status'] ?? 'unknown'),
            'provider_snapshot' => $data,
            'redirect_url' => $data['next_actions']['redirect_url'] ?? null,
            'qr_payload' => $data['next_actions']['qr_payload'] ?? null,
            'qr_expires_at' => isset($data['next_actions']['expires_at'])
                ? \Carbon\Carbon::createFromTimestampMs($data['next_actions']['expires_at'] / 1000000)
                : null,
            'payment_date' => now(),
            'state' => 1,
        ]);

        return response()->json([
            'message' => 'Pago procesado con Bold',
            'payment' => $payment,
            'bold_response' => $data,
        ]);
    }

    /**
     * Consultar estado del pago (GET /v1/payment/{reference_id})
     * Aquí se genera el Payment y se actualiza la orden solo si el status es APPROVED
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

        $data = $response->json()['payload'] ?? $response->json();
        $status = strtoupper($data['status'] ?? '');

        // Buscar la orden asociada a este reference_id
        $paymentIntent = PaymentIntent::where('bold_reference_id', $reference)->first();

        if (!$paymentIntent) {
            return response()->json([
                'message' => 'No se encontró PaymentIntent para esta referencia',
            ], 404);
        }

        $order = OrdersSales::find($paymentIntent->orderSales_id);

        // ✅ Si el pago fue aprobado y aún no hemos generado el Payment
        if ($status === 'APPROVED') {

            // Evitar duplicar el registro de Payment
            if (!Payment::where('orderSales_id', $order->orderSales_id)
                ->where('provider_payment_id', $data['transaction_id'] ?? '')
                ->exists()) {

                $payment = Payment::create([
                    'orderSales_id'       => $order->orderSales_id,
                    'methods_id'          => 2,
                    'provider'            => 'bold',
                    'provider_payment_id' => $data['transaction_id'] ?? null,
                    'amount'              => $order->total,
                    'subtotal'            => $order->total,
                    'total'               => $order->total,
                    'payment_status'      => 1,
                    'status'              => strtolower($status),
                    'provider_snapshot'   => $data,
                    'payment_date'        => now(),
                    'state'               => 1,
                ]);

                // Actualizar el estado de pago en OrdersSales
                $order->update([
                    'payment_state' => 'approved',
                ]);
            }
        }

        return response()->json([
            'payment_status' => $status,
            'data' => $data,
            'order_payment_state' => $order->payment_state ?? null,
        ]);
    }
}
