<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Buyer;
use App\Models\Domiciliary;
use App\Models\OrderGeolocation;
use App\Models\OrderSale;
use App\Models\OrderSaleDetail;
use App\Models\Payment;
use App\Models\PaymentForms;
use App\Models\PaymentMethods;
use App\Models\UserAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class OrderService
{
    protected PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Obtener todas las órdenes de un usuario
     */
    public function getOrdersUser(int $userId): array
    {
        $buyer = Buyer::where('user_id', $userId)->first();

        if (!$buyer) {
            throw new \Exception('Usuario comprador no encontrado', 404);
        }

        $orders = OrderSale::where('buyer_id', $buyer->id)
            ->orderBy('sale_date', 'desc')
            ->with('details.product.category', 'business', 'promotions', 'payments', 'address.municipality.department.country', 'address.alias', 'domiciliary.user')
            ->get();

        $formattedOrders = $orders->map(function ($order) {
            return [
                'order_id' => $order->id,
                'buyer_id' => $order->buyer_id,
                'business_id' => $order->business_id,
                'total' => $order->total,
                'sale_date' => $order->sale_date,
                'is_scheduled' => $order->is_scheduled,
                'delivery_date' => $order->delivery_date,
                'delivery_type' => $order->pickup ? 'pickup' : 'delivery',
                'pickup' => (bool) $order->pickup,
                'pickup_time' => $order->pickup_time,
                'has_review' => $order->has_review,
                'state' => $order->state,
                'business' => [
                    'business_id' => $order->business->id,
                    'name' => $order->business->name,
                    'address' => $order->business->address,
                    'latitude' => $order->business->latitude !== null ? (float) $order->business->latitude : null,
                    'longitude' => $order->business->longitude !== null ? (float) $order->business->longitude : null,
                    'phone' => $order->business->phone,
                    'state' => $order->business->state,
                    'logo' => $order->business->logo,
                ],
                'billing_address' => !$order->pickup && $order->address ? [
                    'address_id' => $order->address->id,
                    'address' => $order->address->address,
                    'alias' => $order->address->alias?->name,
                    'municipality' => $order->address->municipality?->name,
                    'department' => $order->address->department?->name,
                    'country' => $order->address->country?->name,
                    'latitude' => $order->address->latitude !== null ? (float) $order->address->latitude : null,
                    'longitude' => $order->address->longitude !== null ? (float) $order->address->longitude : null,
                ] : null,
                'domiciliary' => $order->domiciliary ? [
                    'name' => $order->domiciliary->user->name,
                    'email' => $order->domiciliary->user->email,
                    'phone' => $order->domiciliary->user->phone,
                    'domiciliary_id' => $order->domiciliary->id,
                    'available' => $order->domiciliary->available,
                    'qualification' => $order->domiciliary->qualification,
                    'state' => $order->domiciliary->state,
                    'user_id' => $order->domiciliary->user->id,
                ] : null,
                'details' => $order->details->map(function ($detail) {
                    return [
                        'product_id' => $detail->product->id,
                        'name' => $detail->product->name,
                        'description' => $detail->product->description,
                        'category' => $detail->product->category?->name,
                        'image' => $detail->product->image,
                        'quantity' => $detail->quantity,
                        'unit_price' => $detail->unit_price,
                    ];
                }),
                'promotions' => $order->promotions,
                'payments' => $order->payments,
            ];
        });

        return $formattedOrders->toArray();
    }

    /**
     * Obtener todas las órdenes de un negocio (tendero)
     */
    public function getOrdersBusiness(int $businessId): array
    {
        $business = Business::find($businessId);

        if (!$business) {
            throw new \Exception('Negocio no encontrado', 404);
        }

        $orders = OrderSale::where('business_id', $business->id)
            ->with([
                'business',
                'details.product',
                'buyer.user',
                'promotions',
                'payments',
                'address.municipality.department.country',
                'address.alias'
            ])
            ->get();

        $businessData = [
            'business_id' => $business->id,
            'name' => $business->name,
            'address' => $business->address,
            'latitude' => $business->latitude !== null ? (float) $business->latitude : null,
            'longitude' => $business->longitude !== null ? (float) $business->longitude : null,
            'phone' => $business->phone,
            'qualification' => $business->qualification,
            'state' => $business->state,
            'logo' => $business->logo,
        ];

        $formattedOrders = $orders->map(function ($order) {
            return [
                'order_id' => $order->id,
                'buyer_id' => $order->buyer_id,
                'business_id' => $order->business_id,
                'total' => $order->total,
                'sale_date' => $order->sale_date,
                'is_scheduled' => $order->is_scheduled,
                'delivery_date' => $order->delivery_date,
                'delivery_type' => $order->pickup ? 'pickup' : 'delivery',
                'pickup' => (bool) $order->pickup,
                'pickup_time' => $order->pickup_time,
                'state' => $order->state,
                'buyer' => $order->buyer ? [
                    'buyer_id' => $order->buyer->id,
                    'qualification' => $order->buyer->qualification,
                    'state' => (bool) $order->buyer->state,
                    'user' => [
                        'user_id' => $order->buyer->user->id,
                        'name' => $order->buyer->user->name,
                        'email' => $order->buyer->user->email,
                        'phone' => $order->buyer->user->phone,
                        'address' => $order->buyer->user->address,
                        'rol_id' => $order->buyer->user->rol_id,
                        'qualification' => $order->buyer->user->qualification,
                        'state' => (bool) $order->buyer->user->state,
                    ]
                ] : null,
                'business' => [
                    'business_id' => $order->business->id,
                    'name' => $order->business->name,
                    'address' => $order->business->address,
                    'latitude' => $order->business->latitude !== null ? (float) $order->business->latitude : null,
                    'longitude' => $order->business->longitude !== null ? (float) $order->business->longitude : null,
                    'phone' => $order->business->phone,
                    'state' => $order->business->state,
                    'logo' => $order->business->logo,
                ],
                'delivery_address' => !$order->pickup && $order->address ? [
                    'address_id' => $order->address->id,
                    'address' => $order->address->address,
                    'alias' => $order->address->alias?->name,
                    'municipality' => $order->address->municipality?->name,
                    'department' => $order->address->department?->name,
                    'country' => $order->address->country?->name,
                    'latitude' => $order->address->latitude !== null ? (float) $order->address->latitude : null,
                    'longitude' => $order->address->longitude !== null ? (float) $order->address->longitude : null,
                ] : null,
                'domiciliary' => $order->domiciliary ? [
                    'name' => $order->domiciliary->user->name,
                    'email' => $order->domiciliary->user->email,
                    'phone' => $order->domiciliary->user->phone,
                    'domiciliary_id' => $order->domiciliary->id,
                    'available' => $order->domiciliary->available,
                    'qualification' => $order->domiciliary->qualification,
                    'state' => $order->domiciliary->state,
                    'user_id' => $order->domiciliary->user->id,
                ] : null,
                'details' => $order->details->map(function ($detail) {
                    return [
                        'product_id' => $detail->product->id,
                        'name' => $detail->product->name,
                        'description' => $detail->product->description,
                        'category' => $detail->product->category?->name,
                        'image' => $detail->product->image,
                        'quantity' => $detail->quantity,
                        'unit_price' => $detail->unit_price,
                    ];
                }),
                'promotions' => $order->promotions,
                'payments' => $order->payments,
            ];
        });

        return [
            'business' => $businessData,
            'orders' => $formattedOrders->toArray()
        ];
    }

    /**
     * Calcular métricas de ingresos para un negocio
     */
    public function getIncomeBusiness(int $businessId): array
    {
        $currentWeekStart = now()->startOfWeek();
        $currentWeekEnd = (clone $currentWeekStart)->endOfWeek();

        $previousWeekStart = (clone $currentWeekStart)->subWeek();
        $previousWeekEnd = (clone $previousWeekStart)->endOfWeek();

        $incomeWeek = Payment::select(
            DB::raw('DAYOFWEEK(payment_date) as weekday'),
            DB::raw('SUM(total) as total_income')
        )
            ->whereHas(
                'order',
                fn($q) => $q->where('business_id', $businessId)
            )
            ->whereBetween(
                'payment_date',
                [$currentWeekStart->toDateString(), $currentWeekEnd->toDateString()]
            )
            ->groupBy('weekday')
            ->get()
            ->keyBy('weekday');

        $daysOfWeek = [
            2 => 'Lunes',
            3 => 'Martes',
            4 => 'Miércoles',
            5 => 'Jueves',
            6 => 'Viernes',
            7 => 'Sábado',
            1 => 'Domingo',
        ];

        $weeklyIncome = [];
        foreach ($daysOfWeek as $key => $day) {
            $weeklyIncome[$day] = (float) ($incomeWeek[$key]->total_income ?? 0);
        }

        $currentWeekTotal = array_sum($weeklyIncome);

        $previousWeekTotal = Payment::whereHas(
            'order',
            fn($q) => $q->where('business_id', $businessId)
        )
            ->whereBetween(
                'payment_date',
                [$previousWeekStart->toDateString(), $previousWeekEnd->toDateString()]
            )
            ->sum('total');

        $difference = $currentWeekTotal - $previousWeekTotal;
        $percentageChange = $previousWeekTotal > 0
            ? ($difference / $previousWeekTotal) * 100
            : 100;

        $month_start = now()->startOfMonth();
        $month_end = (clone $month_start)->endOfMonth();

        $monthlyIncome = Payment::whereHas(
            'order',
            fn($q) => $q->where('business_id', $businessId)
        )
            ->whereBetween(
                'payment_date',
                [$month_start->toDateString(), $month_end->toDateString()]
            )
            ->sum('total');

        $totalIncome = Payment::whereHas(
            'order',
            fn($q) => $q->where('business_id', $businessId)
        )->sum('total');

        return [
            'business_id' => $businessId,
            'week_start' => $currentWeekStart->toDateString(),
            'week_end' => $currentWeekEnd->toDateString(),
            'weekly_income' => $weeklyIncome,
            'current_week_income' => (float) $currentWeekTotal,
            'previous_week_income' => (float) $previousWeekTotal,
            'difference' => (float) $difference,
            'percentage_change' => round($percentageChange, 2),
            'month_start' => $month_start->toDateString(),
            'month_end' => $month_end->toDateString(),
            'monthly_income' => (float) $monthlyIncome,
            'total_income' => (float) $totalIncome,
        ];
    }

    /**
     * Crear orden de venta y procesar pago online si aplica
     */
    public function storeOrder(array $data): array
    {
        // 1. Validar la pasarela de pago obligatoria y activa antes de crear cualquier registro
        $gatewayId = $data['payment_gateway_id'] ?? null;
        if (!$gatewayId) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'payment_gateway_id' => ['La pasarela de pago es obligatoria.']
            ]);
        }

        $gatewayRecord = \App\Models\PaymentGateway::find($gatewayId);
        if (!$gatewayRecord) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'payment_gateway_id' => ['La pasarela de pago seleccionada no es válida.']
            ]);
        }

        if (!$gatewayRecord->state) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'payment_gateway_id' => ["La pasarela de pago '{$gatewayRecord->name}' no está activa."]
            ]);
        }

        // Mapear nombre de la pasarela para ser consumido por PaymentService
        $data['payment_gateway'] = $gatewayRecord->name;

        $buyer = Buyer::with(['user', 'TypeDocumentIdentification'])->find($data['payer_id']);
        if (!$buyer) {
            throw new \Exception('Usuario comprador no encontrado', 404);
        }

        $business = Business::find($data['business_id']);
        if (!$business) {
            throw new \Exception('Negocio no encontrado', 404);
        }

        $address = UserAddress::find($data['address_id']);
        if (!$address) {
            throw new \Exception('Dirección no encontrada', 404);
        }

        return DB::transaction(function () use ($data, $buyer, $business, $address) {
            $total = collect($data['products'])
                ->sum(fn($p) => $p['quantity'] * $p['unit_price']);

            $order = OrderSale::create([
                'buyer_id' => $buyer->id,
                'business_id' => $business->id,
                'address_id' => $address ? $address->id : null,
                'methods_id' => $data['payment_method_id'],
                'forms_id' => $data['payment_form_id'],
                'total' => $total, // Actualizaremos este total en el flujo más adelante si hay domicilio en la compra.
                'delivery_distance_meters' => null,
                'delivery_fee_applied' => null,
                'sale_date' => now(),
                'delivery_date' => now(),
                'is_scheduled' => false,
                'pickup' => $data['pickup'] ?? false,
                'pickup_time' => ($data['pickup'] ?? false) && isset($data['pickup_time'])
                    ? \Carbon\Carbon::parse($data['pickup_time'])->format('Y-m-d H:i:s')
                    : null,
                'state' => 1,
                'payment_state' => in_array($data['payment_method_id'], [2, 5])
                    ? 'pending_online'
                    : 'pending_cash'
            ]);

            foreach ($data['products'] as $p) {
                OrderSaleDetail::create([
                    'order_sales_id' => $order->id,
                    'product_id' => $p['product_id'],
                    'quantity' => $p['quantity'],
                    'unit_price' => $p['unit_price'],
                ]);
            }

            /* ========= PAGO ONLINE ========= */
            $boldPaymentData = null;

            if (in_array($data['payment_method_id'], [2, 5])) {
                // Iniciar flujo de pago completo
                $boldPaymentData = $this->paymentService->initiatePayment($order, $data);
                $order->payment_state = 'pending_online';
                $order->save();
            }

            $responseData = [
                'order' => $order->load('details.product.category', 'business', 'address', 'promotions', 'payments')->toApi(),
            ];

            if ($boldPaymentData) {
                $responseData = array_merge($responseData, $boldPaymentData);
            }

            return $responseData;
        });
    }

    /**
     * Obtener métodos de pago activos
     */
    public function getPaymentMethods()
    {
        return PaymentMethods::with('forms')
            ->where('state', 1)
            ->get();
    }

    /**
     * Obtener formas de pago activas
     */
    public function getPaymentForms()
    {
        return PaymentForms::with('methods')
            ->where('state', 1)
            ->get();
    }

    /**
     * Actualizar estado de la orden (números)
     */
    public function updateOrderStatus(int $orderId, int $state, ?int $userId): array
    {
        $order = OrderSale::with('details')->find($orderId);

        if (!$order) {
            throw new \Exception('Orden no encontrada', 404);
        }

        // Acepta pedido
        if ($order->state == 1 && $state == 2) {
            $order->state = 2;

            // Acepta domiciliario
        } elseif ($order->state == 2 && $state == 3) {
            if (!$userId) {
                throw new \Exception('Se requiere el user_id del domiciliario para esta transición.', 422);
            }

            $domiciliary = Domiciliary::where('user_id', $userId)->first();
            if (!$domiciliary) {
                throw new \Exception('Domiciliario no encontrado', 404);
            }

            $order->state = 3;
            $order->domiciliary_id = $domiciliary->id;

            // Pedido entregado
        } elseif ($order->state == 3 && $state == 4) {
            $order->state = 4;
            $order->delivery_date = now();

            // ==========================================
            // POSTGIS Y CÁLCULO DINÁMICO DE TARIFA
            // ==========================================
            $business = \App\Models\Business::find($order->business_id);
            $address = \App\Models\UserAddress::find($order->address_id);

            $distance = 0;
            $deliveryFee = 2000; // Tarifa base por defecto

            if ($business && $address) {
                // Cálculo de distancia en metros exactos usando PostGIS
                $postgisQuery = \Illuminate\Support\Facades\DB::selectOne("
                    SELECT ST_Distance(
                        (SELECT location FROM business WHERE id = ?),
                        (SELECT location FROM user_address WHERE id = ?)
                    ) as meters
                ", [$business->id, $address->id]);

                if ($postgisQuery && $postgisQuery->meters !== null) {
                    $distance = (int) ceil($postgisQuery->meters);
                    
                    // Buscar tarifa dinámica en base de datos
                    $dynamicFee = \App\Models\DeliveryDistanceRate::getPriceForDistance($distance);
                    if ($dynamicFee !== null) {
                        $deliveryFee = $dynamicFee;
                    }
                }
            }

            $order->delivery_distance_meters = $distance;
            $order->delivery_fee_applied = $deliveryFee;

            // si método de pago == 1 (efectivo), crear pago local
            if ($order->methods_id == 1) {
                $subtotal = 0;
                foreach ($order->details as $d) {
                    $subtotal += $d->quantity * $d->unit_price;
                }

                $valorPromocion = 0;
                $total = $subtotal - $deliveryFee - $valorPromocion;

                \App\Models\Payment::create([
                    'order_sale_id' => $order->id,
                    'methods_id' => $order->methods_id,
                    'forms_id' => $order->forms_id,
                    'amount' => 1,
                    'subtotal' => $subtotal,
                    'total' => $total,
                    'domicilio' => $deliveryFee,
                    'valor_promocion' => $valorPromocion,
                    'payment_status' => 1,
                    'payment_date' => now(),
                    'state' => 1
                ]);
            }
        } else {
            throw new \Exception('Transición de estado no permitida.', 400);
        }

        $order->save();

        return [
            'order' => $order->load('details.product', 'buyer', 'business', 'address', 'payments')
        ];
    }

    /**
     * Guardar geolocalización de un domiciliario
     */
    public function storeGeolocation(array $data): OrderGeolocation
    {
        // $data asume que vienen 'latitude' y 'longitude' desde el cliente.
        // Lo convertiremos a PostGIS Point
        $geo = new OrderGeolocation();
        $geo->domiciliary_id = $data['domiciliary_id'];
        $geo->state = $data['state'] ?? 1;
        $geo->save();

        if (isset($data['latitude']) && isset($data['longitude'])) {
            \Illuminate\Support\Facades\DB::table('order_geolocations')
                ->where('id', $geo->id)
                ->update([
                    'location' => \Illuminate\Support\Facades\DB::raw("ST_MakePoint({$data['longitude']}, {$data['latitude']})")
                ]);
        }

        return $geo;
    }

    /**
     * Obtener última geolocalización
     */
    public function getLatestGeolocation(int $domiciliaryId): array
    {
        // Se extrae la longitud (ST_X) y latitud (ST_Y) del punto PostGIS
        $last = \Illuminate\Support\Facades\DB::table('order_geolocations')
            ->select('id', 'domiciliary_id', 'state', 'created_at', 
                     \Illuminate\Support\Facades\DB::raw('ST_X(location::geometry) as longitude, ST_Y(location::geometry) as latitude'))
            ->where('domiciliary_id', $domiciliaryId)
            ->orderByDesc('created_at')
            ->first();

        if ($last) {
            return [
                'latitude' => (float) $last->latitude,
                'longitude' => (float) $last->longitude,
                'domiciliary_id' => $last->domiciliary_id,
                'state' => $last->state,
                'created_at' => $last->created_at,
            ];
        }

        return [];
    }

    /**
     * Obtener órdenes pendientes de revisión
     */
    public function getOrdersPendingReview(): array
    {
        $orders = OrderSale::where('state', 4)
            ->where('has_review', false)
            ->where('pickup', false)
            ->with([
                'details.product.category',
                'business',
                'promotions',
                'payments',
                'address.municipality.department.country',
                'address.alias',
                'domiciliary.user'
            ])
            ->get();

        $formattedOrders = $orders->map(function ($order) {
            return $order->toApi();
        });

        return $formattedOrders->toArray();
    }
}
