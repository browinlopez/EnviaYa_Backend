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
            'products'             => 'required|array|min:1',
        ]);

        $order = OrdersSales::findOrFail($request->orderSales_id);

        // Metadata como objeto
        $metadata = $request->metadata ?? [
            'key'   => 'order_id',
            'value' => (string) $request->orderSales_id
        ];

        // Normalizar payer
        $payer = [
            'person_type'     => $request->payer['person_type'],
            'name'            => $request->payer['name'],
            'phone'           => $request->payer['phone'],
            'email'           => $request->payer['email'],
            'document_type'   => $request->payer['document_type'],
            'document_number' => strval($request->payer['document_number']), // convertir a string
            'billing_address' => [
                'street1'  => $request->payer['billing_address']['street1'] ?? 'Sin dirección',
                'street2'  => $request->payer['billing_address']['street2'] ?? '',
                'city'     => $request->payer['billing_address']['city'] ?? 'Barranquilla',
                'zip_code' => str_pad($request->payer['billing_address']['zip_code'] ?? '08001', 5, '0', STR_PAD_LEFT),
                'province' => $request->payer['billing_address']['province'] ?? 'Atlántico',
                'country'  => $request->payer['billing_address']['country'] ?? 'CO',
                'phone'    => $request->payer['billing_address']['phone'] ?? $request->payer['phone'],
            ],
        ];

        // Normalizar productos
        $products = array_map(function ($item) {
            return [
                'product_id' => $item['product_id'],
                'amount'     => intval($item['amount']),
                'unit_price' => floatval($item['unit_price']), // convertir a número
            ];
        }, $request->products);

        // Payment method
        $paymentMethod = [
            'name'         => $request->payment_method['name'],
            'installments' => intval($request->payment_method['installments'] ?? 1),
        ];

        if (!empty($request->payment_method['card_number'])) {
            $cardNumber = preg_replace('/\D/', '', $request->payment_method['card_number']); // quitar caracteres no numéricos
            if (strlen($cardNumber) !== 16) {
                return response()->json([
                    'message' => 'El número de tarjeta debe tener 16 dígitos'
                ], 422);
            }

            $paymentMethod = array_merge($paymentMethod, [
                'card_number'      => $cardNumber,
                'cardholder_name'  => $request->payment_method['cardholder_name'],
                'expiration_month' => $request->payment_method['expiration_month'],
                'expiration_year'  => $request->payment_method['expiration_year'],
                'cvc'              => $request->payment_method['cvc'],
            ]);
        } elseif (!empty($request->payment_method['token'])) {
            $paymentMethod['token'] = $request->payment_method['token'];
        }

        // Device fingerprint
        $deviceFingerprint = $request->device_fingerprint ?? [
            'ip'               => $request->ip(),
            'device_type'      => 'WEB',
            'os'               => '',
            'model'            => '',
            'browser'          => '',
            'java_enabled'     => false,
            'language'         => 'es',
            'color_depth'      => 24,
            'screen_height'    => 1080,
            'screen_width'     => 1920,
            'time_zone_offset' => now()->offsetHours() * -60,
        ];

        // Body final para Bold
        $body = [
            'reference_id'       => $request->reference_id,
            'metadata'           => $metadata,
            'payer'              => $payer,
            'products'           => $products,
            'payment_method'     => $paymentMethod,
            'device_fingerprint' => $deviceFingerprint,
        ];

        // Enviar a Bold
        $response = Http::withHeaders($this->boldHeaders())
            ->post("{$this->boldApiUrl}/v1/payment", $body);

        if ($response->failed()) {
            return response()->json([
                'message' => 'Error al intentar el pago en Bold',
                'error'   => $response->json()
            ], 422);
        }

        $data = $response->json()['payload'] ?? $response->json();

        // Crear registro de pago
        $payment = Payment::create([
            'orderSales_id'       => $order->orderSales_id,
            'methods_id'          => 2,
            'provider'            => 'bold',
            'provider_payment_id' => $data['transaction_id'] ?? null,
            'amount'              => $order->total,
            'subtotal'            => $order->total,
            'total'               => $order->total,
            'payment_status'      => strtoupper($data['status'] ?? '') === 'APPROVED' ? 1 : 0,
            'status'              => strtolower($data['status'] ?? 'unknown'),
            'provider_snapshot'   => $data,
            'payment_date'        => now(),
            'state'               => 1,
        ]);

        if (!empty($data['status']) && strtoupper($data['status']) === 'APPROVED') {
            $order->update(['payment_state' => 'paid']);
        }

        return response()->json([
            'message'       => 'Pago procesado con Bold',
            'payment'       => $payment,
            'bold_response' => $data
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
