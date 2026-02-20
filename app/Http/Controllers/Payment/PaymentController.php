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

        $intent = PaymentIntent::create([
            'orderSales_id'     => $order->orderSales_id,
            'provider'          => 'bold',
            'bold_reference_id' => $reference,
            'amount'            => $order->total,
            'currency'          => 'COP',
            'status'            => $data['status'] ?? null,
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
            'payment_method_data'  => 'required|array'
        ]);

        $body = array_merge([
            "reference_id" => $request->reference_id,
        ], $request->payment_method_data);

        $response = Http::withHeaders($this->boldHeaders())
            ->post("{$this->boldApiUrl}/v1/payment", $body);

        if ($response->failed()) {
            return response()->json([
                'message' => 'Error al intentar el pago en Bold',
                'error'   => $response->body()
            ], 422);
        }

        $data = $response->json();

        if (!empty($data['status']) && strtoupper($data['status']) === 'APPROVED') {

            $payment = Payment::create([
                'orderSales_id'       => $request->orderSales_id,
                'methods_id'          => $request->methods_id ?? null,
                'provider'            => 'bold',
                'provider_payment_id' => $data['id'] ?? null,
                'amount'              => $data['amount']['total_amount'] ?? 0,
                'subtotal'            => $data['amount']['total_amount'] ?? 0,
                'total'               => $data['amount']['total_amount'] ?? 0,
                'payment_status'      => 1,
                'status'              => strtolower($data['status']),
                'provider_snapshot'   => $data,
                'payment_date'        => now(),
                'state'               => 1,
            ]);

            OrdersSales::find($request->orderSales_id)->update([
                'payment_state' => 'paid',
            ]);

            return response()->json([
                'message' => 'Pago aprobado',
                'payment' => $payment,
                'bold_response' => $data
            ]);
        }

        return response()->json([
            'message' => 'Pago no aprobado',
            'status'  => $data['status'] ?? null,
            'data'    => $data
        ], 422);
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
