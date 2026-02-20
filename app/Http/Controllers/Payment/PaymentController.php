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
        $this->boldApiUrl = config('services.bold.base_url'); // ejemplo: https://integrations.api.bold.co
        $this->boldApiKey = config('services.bold.api_key');  // tu API Key de Bold
    }

    private function boldHeaders()
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'x-api-key ' . $this->boldApiKey,
        ];
    }

    /**
     * Genera un link de pago en Bold para la orden.
     */
    public function createPaymentLink(Request $request)
    {
        $data = $request->validate([
            'orderSales_id' => 'required|exists:orderssales,orderSales_id',
            'description' => 'nullable|string|max:100',
            'expiration_minutes' => 'nullable|integer',
        ]);

        $order = OrdersSales::findOrFail($data['orderSales_id']);

        if ($order->methods_id == 1) {
            return response()->json([
                'message' => 'Este pedido es de pago en efectivo y no requiere un link de pago.'
            ], 422);
        }

        // Generar referencia única para este pago
        $reference = 'VPY-LNK-' . Str::uuid();

        // Construir el body para Bold
        $body = [
            'amount_type' => 'CLOSE',
            'amount' => [
                'currency' => 'COP',
                'total_amount' => $order->total,
            ],
            'reference' => $reference,
            'description' => $data['description'] ?? "Pago orden #{$order->orderSales_id}",
        ];

        // Si se pasó expiración, se calcula nanosegundos
        if (!empty($data['expiration_minutes'])) {
            $body['expiration_date'] = intval(now()->addMinutes($data['expiration_minutes'])->timestamp * 1e9);
        }

        // Llamada a la API de Bold para crear link
        $response = Http::withHeaders($this->boldHeaders())
            ->post("{$this->boldApiUrl}/online/link/v1", $body);

        if ($response->failed()) {
            return response()->json([
                'message' => 'Error al crear link de pago en Bold',
                'error' => $response->body()
            ], 500);
        }

        $responseData = $response->json();

        // Guardamos payment_intent con info mínima (opcional)
        $intent = PaymentIntent::create([
            'orderSales_id' => $order->orderSales_id,
            'provider' => 'bold',
            'bold_reference_id' => $reference,
            'amount' => $order->total,
            'currency' => 'COP',
            'status' => 'link_created',
            'response' => $responseData,
        ]);

        return response()->json([
            'message' => 'Link de pago generado',
            'payment_link' => $responseData['payload']['payment_link'] ?? null,
            'url' => $responseData['payload']['url'] ?? null,
            'reference' => $reference,
        ]);
    }

    /**
     * Consulta el estado del link de pago.
     */
    public function checkPaymentLinkStatus(Request $request)
    {
        $data = $request->validate([
            'payment_link' => 'required|string'
        ]);

        $response = Http::withHeaders($this->boldHeaders())
            ->get("{$this->boldApiUrl}/online/link/v1/{$data['payment_link']}");

        if ($response->failed()) {
            return response()->json([
                'message' => 'Error consultando estado del link',
                'error' => $response->body()
            ], 500);
        }

        $linkData = $response->json();

        // Guardar eventos o actualizar la orden si está pagado
        if (!empty($linkData['status']) && strtoupper($linkData['status']) === 'PAID') {

            // Actualizar orden si no tiene pago
            $order = OrdersSales::where('orderSales_id', $linkData['reference'])->first();

            if ($order) {
                $order->update(['payment_state' => 'paid']);

                // Crear payment si no existe
                Payment::firstOrCreate([
                    'orderSales_id' => $order->orderSales_id,
                    'provider_payment_id' => $linkData['transaction_id'] ?? null,
                ], [
                    'methods_id' => $order->methods_id,
                    'provider' => 'bold',
                    'amount' => $linkData['total'] ?? $order->total,
                    'subtotal' => $linkData['subtotal'] ?? $order->total,
                    'total' => $linkData['total'] ?? $order->total,
                    'payment_status' => 1,
                    'status' => 'paid',
                    'provider_snapshot' => $linkData,
                    'payment_date' => now(),
                    'state' => 1
                ]);
            }
        }

        return response()->json([
            'link_status' => $linkData['status'] ?? null,
            'details' => $linkData,
        ]);
    }
}
