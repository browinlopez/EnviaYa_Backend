<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateIntentPaymentRequest;
use App\Http\Requests\Api\MakePaymentPaymentRequest;
use App\Models\OrderSale;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    protected PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Crear intención de pago y procesar pago (flujo unificado)
     */
    public function createIntent(OrderSale $order, CreateIntentPaymentRequest $request): JsonResponse
    {
        try {
            $responseData = $this->paymentService->initiatePayment($order, $request->validated());
            return response()->json($responseData);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al iniciar el pago',
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Procesar pago (endpoint legacy/custom)
     */
    public function makePayment(MakePaymentPaymentRequest $request): JsonResponse
    {
        try {
            $order = OrderSale::with(['buyer.user', 'buyer.TypeDocumentIdentification', 'address.municipality.department'])->findOrFail($request->order_id);
            $responseData = $this->paymentService->initiatePayment($order, $request->validated());
            return response()->json($responseData);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al procesar el pago',
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Consultar estado del pago
     */
    public function checkStatus(string $ref): JsonResponse
    {
        try {
            $statusData = $this->paymentService->checkPaymentStatus($ref);
            return response()->json($statusData);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al consultar el estado del pago',
                'error' => $e->getMessage()
            ], 400);
        }
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
}

