<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IncomeBusinessOrderRequest;
use App\Http\Requests\Api\OrdersBusinessOrderRequest;
use App\Http\Requests\Api\OrdersUserOrderRequest;
use App\Http\Requests\Api\StoreOrderRequest;
use App\Http\Requests\Api\UpdateStatusOrderRequest;
use App\Http\Requests\Api\StoreGeolocationOrderRequest;
use App\Http\Requests\Api\LatestGeolocationOrderRequest;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    protected OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    /**
     * Obtener todas las órdenes de un usuario
     */
    public function ordersUser(OrdersUserOrderRequest $request): JsonResponse
    {
        try {
            $orders = $this->orderService->getOrdersUser($request->user_id);
            return response()->json([
                'message' => 'Órdenes encontradas',
                'orders' => $orders
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], $e->getCode() ?: 400);
        }
    }

    /**
     * Obtener todas las órdenes de un negocio (tendero)
     */
    public function ordersBusiness(OrdersBusinessOrderRequest $request): JsonResponse
    {
        try {
            $data = $this->orderService->getOrdersBusiness($request->business_id);
            return response()->json([
                'message' => 'Órdenes del negocio encontradas',
                'business' => $data['business'],
                'orders' => $data['orders']
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], $e->getCode() ?: 400);
        }
    }

    /**
     * Obtener ingresos y métricas de un negocio
     */
    public function incomeBusiness(IncomeBusinessOrderRequest $request): JsonResponse
    {
        try {
            $metrics = $this->orderService->getIncomeBusiness($request->business_id);
            return response()->json($metrics);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Crear orden de venta
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        try {
            $responseData = $this->orderService->storeOrder($request->validated());
            return response()->json(array_merge([
                'message' => 'Orden creada',
            ], $responseData), 201);
        } catch (\Exception $e) {
            $code = $e->getCode();
            $statusCode = (is_int($code) && $code >= 100 && $code <= 599) ? $code : 422;
            return response()->json([
                'message' => 'Error al crear la orden',
                'error' => $e->getMessage()
            ], $statusCode);
        }
    }

    /**
     * Obtener métodos de pago
     */
    public function paymentMethods(): JsonResponse
    {
        $methods = $this->orderService->getPaymentMethods();
        return response()->json($methods);
    }

    /**
     * Obtener formas de pago
     */
    public function paymentForms(): JsonResponse
    {
        $forms = $this->orderService->getPaymentForms();
        return response()->json($forms);
    }

    /**
     * Actualizar estado de la orden
     */
    public function updateStatus(UpdateStatusOrderRequest $request): JsonResponse
    {
        try {
            $data = $this->orderService->updateOrderStatus(
                $request->order_id,
                $request->state,
                $request->user_id
            );
            return response()->json(array_merge([
                'message' => 'Estado de la orden actualizado',
            ], $data));
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], $e->getCode() ?: 400);
        }
    }

    /**
     * Guardar geolocalización de un domiciliario
     */
    public function storeGeolocation(StoreGeolocationOrderRequest $request): JsonResponse
    {
        try {
            $geo = $this->orderService->storeGeolocation($request->validated());
            return response()->json([
                'success' => true,
                'message' => 'Geolocalización guardada correctamente',
                'data' => $geo
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Obtener última geolocalización
     */
    public function latest(LatestGeolocationOrderRequest $request): JsonResponse
    {
        try {
            $last = $this->orderService->getLatestGeolocation($request->domiciliary_id);
            return response()->json($last);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Obtener órdenes pendientes de revisión
     */
    public function ordersPendingReview(): JsonResponse
    {
        try {
            $orders = $this->orderService->getOrdersPendingReview();
            return response()->json([
                'message' => 'Órdenes pendientes de review',
                'orders' => $orders,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
