<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateIntentPaymentRequest;
use App\Http\Requests\Api\MakePaymentPaymentRequest;
use App\Models\OrderSale;
use App\Models\PaymentIntent;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    protected PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }



    /**
     * Obtener la información de una intención de pago
     */
    public function getPaymentIntent(string $referenceId): JsonResponse
    {
        try {
            $data = $this->paymentService->getPaymentIntent($referenceId);
            return response()->json($data);
        } catch (\Exception $e) {
            $code = $e->getCode();
            $status = ($code >= 400 && $code < 600) ? $code : 400;
            return response()->json([
                'message' => 'Error al obtener la intención de pago',
                'error' => json_decode($e->getMessage()) ?? $e->getMessage()
            ], $status);
        }
    }

    /**
     * Actualizar la información de una intención de pago
     */
    public function updatePaymentIntent(\Illuminate\Http\Request $request): JsonResponse
    {
        try {
            $data = $this->paymentService->updatePaymentIntent($request->all());
            return response()->json($data);
        } catch (\Exception $e) {
            $code = $e->getCode();
            $status = ($code >= 400 && $code < 600) ? $code : 400;
            return response()->json([
                'message' => 'Error al actualizar la intención de pago',
                'error' => json_decode($e->getMessage()) ?? $e->getMessage()
            ], $status);
        }
    }

    /**
     * Obtener el estado de un intento de pago
     */
    public function getPaymentAttempt(string $referenceId): JsonResponse
    {
        try {
            $data = $this->paymentService->getPaymentAttempt($referenceId);
            return response()->json($data);
        } catch (\Exception $e) {
            $code = $e->getCode();
            $status = ($code >= 400 && $code < 600) ? $code : 400;
            return response()->json([
                'message' => 'Error al obtener el intento de pago',
                'error' => json_decode($e->getMessage()) ?? $e->getMessage()
            ], $status);
        }
    }

    /**
     * Obtener el listado de bancos disponibles para PSE
     */
    public function getPseBanks(): JsonResponse
    {
        try {
            $data = $this->paymentService->getPseBanks();
            return response()->json($data);
        } catch (\Exception $e) {
            $code = $e->getCode();
            $status = ($code >= 400 && $code < 600) ? $code : 400;
            return response()->json([
                'message' => 'Error al obtener la lista de bancos PSE',
                'error' => json_decode($e->getMessage()) ?? $e->getMessage()
            ], $status);
        }
    }

    /**
     * Realizar una Anulación de un pago
     */
    public function voidPayment(\Illuminate\Http\Request $request): JsonResponse
    {
        try {
            $this->paymentService->voidPayment($request->all());
            return response()->json(null, 204);
        } catch (\Exception $e) {
            $code = $e->getCode();
            $status = ($code >= 400 && $code < 600) ? $code : 400;
            return response()->json([
                'message' => 'Error al anular el pago',
                'error' => json_decode($e->getMessage()) ?? $e->getMessage()
            ], $status);
        }
    }

    /**
     * Solicitar el reembolso de un pago
     */
    public function refundPayment(\Illuminate\Http\Request $request): JsonResponse
    {
        try {
            $this->paymentService->refundPayment($request->all());
            return response()->json(null, 204);
        } catch (\Exception $e) {
            $code = $e->getCode();
            $status = ($code >= 400 && $code < 600) ? $code : 400;
            return response()->json([
                'message' => 'Error al solicitar el reembolso del pago',
                'error' => json_decode($e->getMessage()) ?? $e->getMessage()
            ], $status);
        }
    }

    /**
     * Consultar el estado de un reembolso
     */
    public function getRefundStatus(string $transactionId): JsonResponse
    {
        try {
            $data = $this->paymentService->getRefundStatus($transactionId);
            return response()->json($data);
        } catch (\Exception $e) {
            $code = $e->getCode();
            $status = ($code >= 400 && $code < 600) ? $code : 400;
            return response()->json([
                'message' => 'Error al obtener el estado del reembolso',
                'error' => json_decode($e->getMessage()) ?? $e->getMessage()
            ], $status);
        }
    }

    /**
     * Reintentar el pago de una orden existente
     */
    public function retryPayment(int $id, \Illuminate\Http\Request $request): JsonResponse
    {
        try {
            $order = OrderSale::with(['buyer.user', 'buyer.TypeDocumentIdentification', 'address.municipality.department'])->findOrFail($id);
            $responseData = $this->paymentService->retryPayment($order, $request->all());
            return response()->json($responseData);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al reintentar el pago',
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Consultar el estado de un pago directamente en la pasarela dinámica y retornar la respuesta cruda (sin transformar)
     */
    public function getRawPaymentStatus(Request $request)
    {
        try {
            $paymentGatewayId = $request->query('paymentGatewayId');
            $referenceIdQuery = $request->query('referenceId');

            if (!$paymentGatewayId || !$referenceIdQuery) {
                return response()->json([
                    'message' => 'Los parámetros paymentGatewayId y referenceId son requeridos.'
                ], 422);
            }

            $referenceId = null;

            if (is_numeric($referenceIdQuery)) {
                // 1. Buscar la orden en la DB si enviaron un ID numérico (lanza 404 si no existe)
                $orderId = (int) $referenceIdQuery;
                $order = OrderSale::findOrFail($orderId);

                // 2. Obtener el reference_id buscando el más reciente en PaymentIntent
                $intent = PaymentIntent::where('order_sale_id', $orderId)->latest()->first();
                $referenceId = $intent ? $intent->bold_reference_id : 'ORD-' . $orderId;
            } else {
                // Si el frontend envía directamente el string de referencia (ej. ORD-21 u ORDER-31)
                $referenceId = $referenceIdQuery;
            }

            if (!$referenceId) {
                return response()->json([
                    'message' => 'La orden no tiene un reference_id asociado'
                ], 422);
            }

            // 3. Consumir directamente la API usando el gateway resuelto
            $response = $this->paymentService->getRawPaymentStatus($referenceId, (int) $paymentGatewayId);

            // 4. Retornar EXACTAMENTE la misma respuesta y status code
            $status = $response->status();
            $body = $response->json() ?? json_decode($response->body(), true) ?? $response->body();

            if (is_array($body)) {
                return response()->json($body, $status);
            }

            // En caso de que no sea JSON por alguna razón, retornar plano
            return response($response->body(), $status)->header('Content-Type', 'application/json');

        } catch (ModelNotFoundException $e) {
            // Orden no existe -> lanzar 404
            return response()->json([
                'message' => 'La orden no existe'
            ], 404);
        } catch (\Exception $e) {
            Log::error("❌ ERROR CONSULTANDO ESTADO BOLD: " . $e->getMessage());
            return response()->json([
                'message' => 'Error al obtener el estado del pago desde Bold',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

