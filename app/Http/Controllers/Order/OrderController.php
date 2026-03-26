<?php

namespace App\Http\Controllers\Order;

use App\Events\DomiciliaryLocationUpdated;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Payment\PaymentController;
use App\Models\Business;
use App\Models\Buyer\Buyer;
use App\Models\Domiciliary;
use App\Models\Order\OrderGeolocation;
use App\Models\Order\OrdersSales;
use App\Models\Order\OrdersSalesDetail;
use App\Models\Payment\Payment;
use App\Models\Payment\PaymentForms;
use App\Models\Payment\PaymentIntent;
use App\Models\Payment\PaymentMethods;
use App\Models\Product\ProductBusiness;
use App\Models\User;
use App\Models\User\UserAddress;
use App\Services\BoldService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    // Función para obtener todas las órdenes de un usuario
    public function ordersUser(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer'
        ]);

        $buyer = Buyer::where('user_id', $request->user_id)->first();

        if (!$buyer) {
            return response()->json([
                'message' => 'Usuario comprador no encontrado'
            ], 404);
        }

        $orders = OrdersSales::where('buyer_id', $buyer->buyer_id)
            ->with('details.product.category', 'business', 'promotions', 'payments', 'address.municipality.department.country', 'address.alias', 'domiciliary.user')
            ->get();

        $formattedOrders = $orders->map(function ($order) {
            return [
                'order_id' => $order->orderSales_id,
                'buyer_id' => $order->buyer_id,
                'busines_id' => $order->busines_id,
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
                    'business_id' => $order->business->busines_id,
                    'name' => $order->business->name,
                    'address' => $order->business->address,
                    'address' => $order->business->address,
                    'latitude' => $order->business->latitude !== null ? (float)$order->business->latitude : null,
                    'longitude' => $order->business->longitude !== null ? (float)$order->business->longitude : null,
                    'phone' => $order->business->phone,
                    'city' => $order->business->city,
                    'state' => $order->business->state,
                    'logo' => $order->business->logo,
                ],
                'delivery_address' => !$order->pickup && $order->address ? [
                    'address_id' => $order->address->address_id,
                    'address' => $order->address->address,
                    'alias' => $order->address->alias?->name,
                    'municipality' => $order->address->municipality?->name,
                    'department' => $order->address->department?->name,
                    'country' => $order->address->country?->name,
                    'latitude' => $order->address->latitude !== null ? (float)$order->address->latitude : null,
                    'longitude' => $order->address->longitude !== null ? (float)$order->address->longitude : null,
                ] : null,
                'domiciliary' => $order->domiciliary ? [
                    'name' => $order->domiciliary->user->name,
                    'email' => $order->domiciliary->user->email,
                    'phone' => $order->domiciliary->user->phone,
                    'domiciliary_id' => $order->domiciliary->domiciliary_id,
                    'available' => $order->domiciliary->available,
                    'qualification' => $order->domiciliary->qualification,
                    'state' => $order->domiciliary->state,
                    'user_id' => $order->domiciliary->user->user_id,
                ] : null,
                'details' => $order->details->map(function ($detail) {
                    return [
                        'product_id' => $detail->product->products_id,
                        'name' => $detail->product->name,
                        'description' => $detail->product->description,
                        'category' => $detail->product->category?->name,
                        'image' => $detail->product->image,
                        'amount' => $detail->amount,
                        'unit_price' => $detail->unit_price,
                    ];
                }),
                'promotions' => $order->promotions,
                'payments' => $order->payments,
            ];
        });

        return response()->json([
            'message' => 'Órdenes encontradas',
            'orders' => $formattedOrders
        ]);
    }

    // Función para obtener todas las órdenes de un negocio (tendero)
    public function ordersBusiness(Request $request)
    {
        $request->validate([
            'business_id' => 'required|integer'
        ]);

        $business = Business::find($request->business_id);

        if (!$business) {
            return response()->json([
                'message' => 'Negocio no encontrado'
            ], 404);
        }

        // Precargamos relaciones necesarias
        $orders = OrdersSales::where('busines_id', $business->busines_id)
            ->with([
                'business',
                'details.product',
                'buyer.user', // Buyer + User
                'promotions',
                'payments',
                'address.municipality.department.country',
                'address.alias'
            ])
            ->get();

        // Datos del business, solo una vez
        $businessData = [
            'business_id' => $business->busines_id,
            'name' => $business->name,
            'address' => $business->address,
            'latitude' => $business->latitude !== null ? (float)$business->latitude : null,
            'longitude' => $business->longitude !== null ? (float)$business->longitude : null,
            'phone' => $business->phone,
            'city' => $business->city,
            'qualification' => $business->qualification,
            'state' => $business->state,
            'logo' => $business->logo,
        ];

        $formattedOrders = $orders->map(function ($order) use ($businessData) {
            return [
                'order_id' => $order->orderSales_id,
                'buyer_id' => $order->buyer_id,
                'busines_id' => $order->busines_id,
                'total' => $order->total,
                'sale_date' => $order->sale_date,
                'is_scheduled' => $order->is_scheduled,
                'delivery_date' => $order->delivery_date,
                'delivery_type' => $order->pickup ? 'pickup' : 'delivery',
                'pickup' => (bool) $order->pickup,
                'pickup_time' => $order->pickup_time,
                'state' => $order->state,
                'buyer' => $order->buyer ? [
                    'buyer_id' => $order->buyer->buyer_id,
                    'qualification' => $order->buyer->qualification,
                    'state' => (bool) $order->buyer->state,
                    'user' => [
                        'user_id' => $order->buyer->user->user_id,
                        'name' => $order->buyer->user->name,
                        'email' => $order->buyer->user->email,
                        'phone' => $order->buyer->user->phone,
                        'address' => $order->buyer->user->address,
                        'rol' => $order->buyer->user->rol,
                        'qualification' => $order->buyer->user->qualification,
                        'state' => (bool) $order->buyer->user->state,
                    ]
                ] : null,
                'business' => [
                    'business_id' => $order->business->busines_id,
                    'name' => $order->business->name,
                    'address' => $order->business->address,
                    'latitude' => $order->business->latitude !== null ? (float)$order->business->latitude : null,
                    'longitude' => $order->business->longitude !== null ? (float)$order->business->longitude : null,
                    'phone' => $order->business->phone,
                    'city' => $order->business->city,
                    'state' => $order->business->state,
                    'logo' => $order->business->logo,
                ],
                'delivery_address' => !$order->pickup && $order->address ? [
                    'address_id' => $order->address->address_id,
                    'address' => $order->address->address,
                    'alias' => $order->address->alias?->name,
                    'municipality' => $order->address->municipality?->name,
                    'department' => $order->address->department?->name,
                    'country' => $order->address->country?->name,
                    'latitude' => $order->address->latitude !== null ? (float)$order->address->latitude : null,
                    'longitude' => $order->address->longitude !== null ? (float)$order->address->longitude : null,
                ] : null,
                'domiciliary' => $order->domiciliary ? [
                    'name' => $order->domiciliary->user->name,
                    'email' => $order->domiciliary->user->email,
                    'phone' => $order->domiciliary->user->phone,
                    'domiciliary_id' => $order->domiciliary->domiciliary_id,
                    'available' => $order->domiciliary->available,
                    'qualification' => $order->domiciliary->qualification,
                    'state' => $order->domiciliary->state,
                    'user_id' => $order->domiciliary->user->user_id,
                ] : null,
                'details' => $order->details->map(function ($detail) {
                    return [
                        'product_id' => $detail->product->products_id,
                        'name' => $detail->product->name,
                        'description' => $detail->product->description,
                        'category' => $detail->product->category?->name,
                        'image' => $detail->product->image,
                        'amount' => $detail->amount,
                        'unit_price' => $detail->unit_price,
                    ];
                }),
                'promotions' => $order->promotions,
                'payments' => $order->payments,
            ];
        });

        return response()->json([
            'message' => 'Órdenes del negocio encontradas',
            'business' => $businessData,  // incluimos business una sola vez
            'orders' => $formattedOrders
        ]);
    }

    public function incomeBusiness(Request $request)
    {
        $request->validate([
            'business_id' => 'required|integer|exists:business,busines_id',
        ]);

        $business_id = $request->business_id;

        /**
         * SEMANA ACTUAL
         */
        $currentWeekStart = now()->startOfWeek();
        $currentWeekEnd   = (clone $currentWeekStart)->endOfWeek();

        /**
         * SEMANA ANTERIOR
         */
        $previousWeekStart = (clone $currentWeekStart)->subWeek();
        $previousWeekEnd   = (clone $previousWeekStart)->endOfWeek();

        /**
         * INGRESOS POR DÍA (SEMANA ACTUAL)
         */
        $incomeWeek = Payment::select(
            DB::raw('DAYOFWEEK(payment_date) as weekday'),
            DB::raw('SUM(total) as total_income')
        )
            ->whereHas(
                'order',
                fn($q) =>
                $q->where('busines_id', $business_id)
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
            $weeklyIncome[$day] = (float)($incomeWeek[$key]->total_income ?? 0);
        }

        /**
         * TOTAL SEMANA ACTUAL
         */
        $currentWeekTotal = array_sum($weeklyIncome);

        /**
         * TOTAL SEMANA ANTERIOR
         */
        $previousWeekTotal = Payment::whereHas(
            'order',
            fn($q) =>
            $q->where('busines_id', $business_id)
        )
            ->whereBetween(
                'payment_date',
                [$previousWeekStart->toDateString(), $previousWeekEnd->toDateString()]
            )
            ->sum('total');

        /**
         * DIFERENCIAS
         */
        $difference = $currentWeekTotal - $previousWeekTotal;
        $percentageChange = $previousWeekTotal > 0
            ? ($difference / $previousWeekTotal) * 100
            : 100;

        /**
         * MES ACTUAL
         */
        $month_start = now()->startOfMonth();
        $month_end   = (clone $month_start)->endOfMonth();

        $monthlyIncome = Payment::whereHas(
            'order',
            fn($q) =>
            $q->where('busines_id', $business_id)
        )
            ->whereBetween(
                'payment_date',
                [$month_start->toDateString(), $month_end->toDateString()]
            )
            ->sum('total');

        /**
         * TOTAL HISTÓRICO
         */
        $totalIncome = Payment::whereHas(
            'order',
            fn($q) =>
            $q->where('busines_id', $business_id)
        )->sum('total');

        return response()->json([
            'business_id' => $business_id,

            'week_start' => $currentWeekStart->toDateString(),
            'week_end'   => $currentWeekEnd->toDateString(),

            'weekly_income' => $weeklyIncome,

            'current_week_income'  => (float)$currentWeekTotal,
            'previous_week_income' => (float)$previousWeekTotal,
            'difference'            => (float)$difference,
            'percentage_change'     => round($percentageChange, 2),

            'month_start'    => $month_start->toDateString(),
            'month_end'      => $month_end->toDateString(),
            'monthly_income' => (float)$monthlyIncome,

            'total_income' => (float)$totalIncome,
        ]);
    }

    // Crear orden de venta
    public function store(Request $request, BoldService $bold)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'busines_id' => 'required|integer',
            'address_id' => 'required_if:pickup,false|integer',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|integer',
            'products.*.amount' => 'required|integer|min:1',
            'products.*.unit_price' => 'required|numeric|min:0',
            'methods_id' => 'required|integer',
            'payer' => 'required_if:methods_id,2,5|array',
            'payment_method' => 'required_if:methods_id,2|array',
            'pickup' => 'sometimes|boolean',
            'pickup_time' => 'nullable|required_if:pickup,true|date',
        ]);

        /* ========= VALIDACIONES REALES ========= */

        $buyer = Buyer::where('user_id', $request->user_id)->first();
        if (!$buyer) {
            return response()->json(['message' => 'Usuario comprador no encontrado'], 404);
        }

        $business = Business::find($request->busines_id);
        if (!$business) {
            return response()->json(['message' => 'Negocio no encontrado'], 404);
        }

        $address = UserAddress::find($request->address_id);
        if (!$address) {
            return response()->json(['message' => 'Dirección no encontrada'], 404);
        }

        DB::beginTransaction();

        try {
            /* ========= ORDEN ========= */

            $total = collect($request->products)
                ->sum(fn($p) => $p['amount'] * $p['unit_price']);

            $order = OrdersSales::create([
                'buyer_id' => $buyer->buyer_id,
                'busines_id' => $business->busines_id,
                'address_id' => $address->address_id,
                'methods_id' => $request->methods_id,
                'total' => $total,
                'sale_date' => now(),
                'delivery_date' => now(),
                'is_scheduled' => false,
                'pickup' => $request->pickup ?? false,
                'pickup_time' => $request->pickup && $request->pickup_time
                    ? \Carbon\Carbon::parse($request->pickup_time)->format('Y-m-d H:i:s')
                    : null,
                'state' => 1,
                'payment_state' => in_array($request->methods_id, [2, 5])
                    ? 'pending_online'
                    : 'pending_cash'
            ]);

            foreach ($request->products as $p) {
                OrdersSalesDetail::create([
                    'orderSales_id' => $order->orderSales_id,
                    'product_id' => $p['product_id'],
                    'amount' => $p['amount'],
                    'unit_price' => $p['unit_price'],
                ]);
            }

            /* ========= PAGO ONLINE ========= */

            if (in_array($request->methods_id, [2, 5])) {

                $paymentController = app(PaymentController::class);

                // 1️⃣ Crear intent
                $intent = $paymentController->createIntent($order, $bold);

                // 2️⃣ PAYER COMPLETO (NO NORMALIZAR)
                $payer = $request->payer;

                // 3️⃣ Método de pago
                $paymentMethod = $request->methods_id == 2
                    ? array_merge(['name' => 'CREDIT_CARD'], $request->payment_method)
                    : ['name' => 'QR'];

                // 4️⃣ Productos
                $products = collect($request->products)->map(fn($p) => [
                    'product_id' => $p['product_id'],
                    'amount' => (int) $p['amount'],
                    'unit_price' => (float) $p['unit_price'],
                ])->toArray();

                // 5️⃣ Ejecutar pago
                $payment = $paymentController->createPayment(
                    $order,
                    $intent,
                    $payer,
                    $paymentMethod,
                    $products,
                    $request,
                    $bold
                );

                // 6️⃣ Estado
                $order->payment_state = $payment->payment_status
                    ? 'paid'
                    : 'pending_online';

                $order->save();
            }

            DB::commit();

            return response()->json([
                'message' => 'Orden creada',
                'order' => $order->load('details.product.category', 'business', 'address', 'promotions', 'payments')->toApi(),
                'bold_reference_id' => $intent->bold_reference_id ?? null,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error al crear la orden',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    // Obtener métodos de pago
    public function paymentMethods()
    {
        $methods = PaymentMethods::with('forms')
            ->where('state', 1)
            ->get();

        return response()->json($methods);
    }

    // Obtener formas de pago
    public function paymentForms()
    {
        $forms = PaymentForms::with('methods')
            ->where('state', 1)
            ->get();

        return response()->json($forms);
    }

    // Actualizar estado de la orden (números)
    public function updateStatus(Request $request)
    {
        $request->validate([
            'order_id' => 'required|integer',
            'state' => 'required|integer|in:2,3,4',
            'user_id' => 'nullable|integer|exists:user,user_id'
        ]);

        $order = OrdersSales::with('details')->find($request->order_id);

        if (!$order) {
            return response()->json(['message' => 'Orden no encontrada'], 404);
        }

        // Acepta pedido
        if ($order->state == 1 && $request->state == 2) {
            $order->state = 2;

            // Acepta domiciliario
        } elseif ($order->state == 2 && $request->state == 3) {
            if (!$request->user_id) {
                return response()->json(['message' => 'Se requiere el user_id del domiciliario para esta transición.'], 422);
            }

            $domiciliary = Domiciliary::where('user_id', $request->user_id)->first();
            if (!$domiciliary) {
                return response()->json(['message' => 'Domiciliario no encontrado'], 404);
            }

            $order->state = 3;
            $order->domiciliary_id = $domiciliary->domiciliary_id;

            // Pedido entregado
        } elseif ($order->state == 3 && $request->state == 4) {
            $order->state = 4;
            $order->delivery_date = now();

            // si método de pago == 1, crear pago
            if ($order->methods_id == 1) {
                // calcular valores
                $subtotal = $order->details->sum(function ($d) {
                    return $d->amount * $d->unit_price;
                });
                $domicilio = 2000; // aquí pones tu cálculo del costo de domicilio
                $valorPromocion = 0; // aquí aplicas descuentos/promociones
                $total = $subtotal - $domicilio - $valorPromocion;

                Payment::create([
                    'orderSales_id' => $order->orderSales_id,
                    'methods_id' => $order->methods_id,
                    'forms_id' => $order->forms_id,
                    'amount' => /* $total */ 1,
                    'subtotal' => $subtotal,
                    'total' => $total,
                    'domicilio' => $domicilio,
                    'valor_promocion' => $valorPromocion,
                    'payment_status' => 1, // por ejemplo pagado
                    'payment_date' => now(),
                    'state' => 1 // activo
                ]);
            }
        } else {
            return response()->json(['message' => 'Transición de estado no permitida.'], 400);
        }

        $order->save();

        return response()->json([
            'message' => 'Estado de la orden actualizado',
            'order' => $order->load('details.product', 'buyer', 'business', 'address', 'payments')
        ]);
    }

    public function updateLocation(Request $request)
    {
        $data = $request->validate([
            'domiciliary_id' => 'required|exists:domiciliary,domiciliary_id',
            'orderSales_id'  => 'required|exists:orderssales,orderSales_id',
            'latitude'       => 'required|numeric',
            'longitude'      => 'required|numeric',
            'state'          => 'nullable|integer'
        ]);

        // Emitimos directamente al servidor Node.js sin guardar en DB
        Http::post('http://192.168.20.29:3000/location', [
            'order_id'       => $data['orderSales_id'],
            'latitude'       => $data['latitude'],
            'longitude'      => $data['longitude'],
            'domiciliary_id' => $data['domiciliary_id'],
        ]);

        return response()->json([
            'message' => 'Ubicación enviada al socket exitosamente',
            'data'    => $data
        ]);
    }

    public function storeGeolocation(Request $request)
    {
        // Validar los datos recibidos
        $data = $request->validate([
            'domiciliary_id' => 'required|exists:domiciliary,domiciliary_id',
            'orderSales_id'  => 'required|exists:orderssales,orderSales_id',
            'latitude'       => 'required|numeric',
            'longitude'      => 'required|numeric',
            'state'          => 'nullable|integer',
        ]);

        // Crear registro en la base de datos
        $geo = OrderGeolocation::create($data);

        // Retornar respuesta JSON
        return response()->json([
            'success' => true,
            'message' => 'Geolocalización guardada correctamente',
            'data' => $geo
        ], 201);
    }

    public function latest(Request $request)
    {
        $data = $request->validate([
            'domiciliary_id' => 'required|exists:domiciliary,domiciliary_id'
        ]);

        $last = OrderGeolocation::where('domiciliary_id', $data['domiciliary_id'])
            ->orderByDesc('created_at')
            ->first();

        if ($last) {
            return response()->json([
                'latitude' => $last->latitude,
                'longitude' => $last->longitude,
                'orderSales_id' => $last->orderSales_id,
                'state' => $last->state,
                'created_at' => $last->created_at,
            ]);
        }

        return response()->json([]); // sin ubicación
    }

    public function ordersPendingReview(Request $request)
    {
        $orders = OrdersSales::where('state', 4)
            ->where('has_review', false) // o 0
            ->where('pickup', false)     // o 0
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
            return $order->toApi(); // usando tu método toApi para mantener consistencia
        });

        return response()->json([
            'message' => 'Órdenes pendientes de review',
            'orders' => $formattedOrders,
        ]);
    }
}
