<?php

namespace App\Http\Controllers\Order;

use App\Services\Ajustes;
use App\Events\DomiciliaryLocationUpdated;
use App\Events\OrderStatusUpdated;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Payment\PaymentController;
use App\Models\Business;
use App\Models\Buyer\Buyer;
use App\Models\Domiciliary;
use App\Models\Order\OrderGeolocation;
use App\Models\Order\OrdersSales;
use App\Models\Order\OrdersSalesDetail;
use App\Models\Payment\Payment;
use App\Services\FacturaService;
use Illuminate\Support\Facades\Log;
use App\Models\Payment\PaymentForms;
use App\Models\Payment\PaymentIntent;
use App\Models\Payment\PaymentMethods;
use App\Models\Product\ProductBusiness;
use App\Models\User;
use App\Models\User\UserAddress;
use App\Services\BoldService;
use App\Services\CouponService;
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
                // Desglose, para que cada rol sepa qué parte le corresponde:
                // el negocio cobra el subtotal y el domiciliario el domicilio.
                'subtotal' => $order->subtotal,
                'domicilio' => $order->domicilio,
                'domiciliary_fee' => $order->domiciliary_fee,
                // El domiciliario necesita saber si cobra en la puerta o
                // si el pedido ya viene pagado en línea.
                'methods_id' => $order->methods_id,
                'payment_state' => $order->payment_state,
                'sale_date' => $order->sale_date,
                'is_scheduled' => $order->is_scheduled,
                'delivery_date' => $order->delivery_date,
                'delivery_type' => $order->pickup ? 'pickup' : 'delivery',
                'pickup' => (bool) $order->pickup,
                'pickup_time' => $order->pickup_time,
                'has_review' => $order->has_review,
                'dispatched_at' => $order->dispatched_at,
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
                // Desglose, para que cada rol sepa qué parte le corresponde:
                // el negocio cobra el subtotal y el domiciliario el domicilio.
                'subtotal' => $order->subtotal,
                'domicilio' => $order->domicilio,
                'domiciliary_fee' => $order->domiciliary_fee,
                // El domiciliario necesita saber si cobra en la puerta o
                // si el pedido ya viene pagado en línea.
                'methods_id' => $order->methods_id,
                'payment_state' => $order->payment_state,
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
         * Una orden cuenta como ingreso cuando su pago online está
         * confirmado (payment_state 'paid') o cuando ya fue entregada
         * (efectivo, state 4). Antes se sumaba desde `payments`, que solo
         * existe para pagos online: las ventas en efectivo no contaban.
         */
        $incomeOrders = fn() => OrdersSales::where('busines_id', $business_id)
            ->where(function ($q) {
                $q->where('payment_state', 'paid')->orWhere('state', 4);
            });

        $daysOfWeek = [
            2 => 'Lunes',
            3 => 'Martes',
            4 => 'Miércoles',
            5 => 'Jueves',
            6 => 'Viernes',
            7 => 'Sábado',
            1 => 'Domingo',
        ];

        // Ingresos por día de la semana para un rango dado
        $incomeByDay = function ($start, $end) use ($incomeOrders, $daysOfWeek) {
            $rows = $incomeOrders()
                ->select(
                    DB::raw('DAYOFWEEK(sale_date) as weekday'),
                    DB::raw('SUM(total) as total_income')
                )
                ->whereBetween('sale_date', [$start, $end])
                ->groupBy('weekday')
                ->get()
                ->keyBy('weekday');

            $out = [];
            foreach ($daysOfWeek as $key => $day) {
                $out[$day] = (float) ($rows[$key]->total_income ?? 0);
            }

            return $out;
        };

        $currentWeekStart = now()->startOfWeek();
        $currentWeekEnd   = (clone $currentWeekStart)->endOfWeek();
        $previousWeekStart = (clone $currentWeekStart)->subWeek();
        $previousWeekEnd   = (clone $previousWeekStart)->endOfWeek();

        $weeklyIncome = $incomeByDay($currentWeekStart, $currentWeekEnd);
        $previousWeeklyIncome = $incomeByDay($previousWeekStart, $previousWeekEnd);

        $currentWeekTotal  = array_sum($weeklyIncome);
        $previousWeekTotal = array_sum($previousWeeklyIncome);

        $difference = $currentWeekTotal - $previousWeekTotal;
        $percentageChange = $previousWeekTotal > 0
            ? ($difference / $previousWeekTotal) * 100
            : 100;

        $month_start = now()->startOfMonth();
        $month_end   = (clone $month_start)->endOfMonth();

        $monthlyIncome = $incomeOrders()
            ->whereBetween('sale_date', [$month_start, $month_end])
            ->sum('total');

        $totalIncome = $incomeOrders()->sum('total');

        return response()->json([
            'business_id' => $business_id,

            'week_start' => $currentWeekStart->toDateString(),
            'week_end'   => $currentWeekEnd->toDateString(),

            'weekly_income' => $weeklyIncome,
            // Semana anterior desglosada por día (antes solo venía el total
            // y la línea comparativa del chart no tenía datos)
            'previous_weekly_income' => $previousWeeklyIncome,

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
        /* ========= COMPATIBILIDAD DE NOMBRES =========
           La app migrada a la API nueva manda business_id / payment_method_id
           / quantity; el contrato original usa busines_id / methods_id /
           amount. Se aceptan ambos. */
        if (!$request->filled('busines_id') && $request->filled('business_id')) {
            $request->merge(['busines_id' => $request->input('business_id')]);
        }
        if (!$request->filled('methods_id') && $request->filled('payment_method_id')) {
            $request->merge(['methods_id' => $request->input('payment_method_id')]);
        }
        $normalizedProducts = collect($request->input('products', []))
            ->map(fn($p) => [
                'product_id' => $p['product_id'] ?? null,
                'amount' => $p['amount'] ?? $p['quantity'] ?? null,
            ])->all();
        $request->merge(['products' => $normalizedProducts]);

        $request->validate([
            'user_id' => 'required|integer|exists:user,user_id',
            'busines_id' => 'required|integer|exists:business,busines_id',
            'address_id' => 'required_if:pickup,false|nullable|integer',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|integer',
            'products.*.amount' => 'required|integer|min:1|max:1000',
            'methods_id' => 'required|integer|exists:payment_methods,methods_id',
            'payer' => 'nullable|array',
            'payment_method' => 'required_if:methods_id,2|array',
            'pickup' => 'sometimes|boolean',
            'pickup_time' => 'nullable|date',
            'is_scheduled' => 'sometimes|boolean',
            'delivery_date' => 'nullable|required_if:is_scheduled,true|date|after:now',
            // Opcional: el código que el usuario escribió en el carrito. El
            // descuento NO llega desde el cliente, se recalcula acá.
            'coupon_code' => 'sometimes|nullable|string|max:40',
            /*
             * El total que la app le PROMETIÓ al usuario. No se usa para
             * cobrar —el importe lo calcula el servidor— sino para detectar
             * que ya no coinciden y no cobrar algo que nadie aceptó. Opcional
             * por compatibilidad: las versiones que no lo manden se comportan
             * como antes.
             */
            'expected_total' => 'sometimes|nullable|numeric|min:0',
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

        /*
         * Y que siga abierto.
         *
         * Filtrar los listados no basta: basta con conservar el identificador
         * —de un favorito guardado, de un pedido anterior, de una pantalla que
         * quedó abierta— para pedirle a una tienda que la operación creía
         * apagada. Acá es donde de verdad se cierra.
         */
        if (!$business->state) {
            return response()->json([
                'message' => 'Este negocio no está recibiendo pedidos ahora mismo.',
            ], 422);
        }

        $isPickup = (bool) ($request->pickup ?? false);

        // La dirección solo aplica (y solo se exige) para domicilio, y debe
        // pertenecer al usuario que ordena.
        $address = null;
        if (!$isPickup) {
            $address = UserAddress::find($request->address_id);
            if (!$address || (int) $address->user_id !== (int) $request->user_id) {
                return response()->json(['message' => 'Dirección no encontrada'], 404);
            }
        }

        /* ========= PRECIOS REALES DEL SERVIDOR =========
           El unit_price NUNCA se toma del cliente: se busca el precio del
           producto en este negocio. De paso valida que cada producto
           realmente pertenezca al negocio. */
        $productIds = collect($request->products)->pluck('product_id')->all();

        $prices = ProductBusiness::where('busines_id', $business->busines_id)
            ->whereIn('products_id', $productIds)
            // Y que el producto siga activo: uno retirado desde el panel no se
            // vende, aunque el carrito del cliente todavía lo lleve dentro.
            ->whereHas('product', fn ($q) => $q->where('state', 1))
            ->pluck('price', 'products_id');

        $missing = collect($productIds)->reject(fn($id) => $prices->has($id));
        if ($missing->isNotEmpty()) {
            return response()->json([
                // El mensaje no distingue "no es de este negocio" de "ya no se
                // vende" a propósito: para quien pide son el mismo problema, y
                // la app tiene que reaccionar igual — quitarlo del carrito.
                'message' => 'Hay productos que ya no están disponibles en este negocio',
                'product_ids' => $missing->values(),
            ], 422);
        }

        /* ========= PROGRAMACIÓN ========= */
        $isScheduled = (bool) ($request->is_scheduled ?? false);
        $deliveryDate = null;

        if ($isScheduled) {
            $deliveryDate = \Carbon\Carbon::parse($request->delivery_date);
            $hour = (int) $deliveryDate->format('G');
            if ($hour < 7 || $hour > 20) {
                return response()->json([
                    'message' => 'Los pedidos programados solo se aceptan entre 7:00 AM y 9:00 PM',
                ], 422);
            }
        }

        DB::beginTransaction();

        try {
            /* ========= ORDEN ========= */

            $subtotal = collect($request->products)
                ->sum(fn($p) => $p['amount'] * (float) $prices[$p['product_id']]);

            /*
             * Tarifa de domicilio, del panel.
             *
             * Vivía en `config/services.php`, o sea en el `.env` del servidor,
             * mientras la app llevaba su propia copia escrita en el código con
             * un comentario que pedía "mantener ambas iguales". No lo estaban:
             * subir la tarifa exigía desplegar el servidor Y publicar una
             * versión nueva en las tiendas, y entre una cosa y otra todos los
             * pedidos mostraban un total y cobraban otro.
             *
             * Se congela en el pedido al crearlo, como el reparto: cambiarla no
             * reescribe lo ya entregado.
             */
            $domicilio = $isPickup ? 0 : (float) Ajustes::valor('operacion.tarifa_domicilio');

            /*
             * Cupón. El descuento se recalcula en el servidor a partir del
             * código: aceptar el monto que mande el cliente sería dejar que
             * cualquiera se ponga el descuento que quiera.
             *
             * Solo aplica sobre el subtotal de productos, nunca sobre el
             * domicilio: esa tarifa es del domiciliario, y descontarla saldría
             * de su bolsillo y no de la promoción.
             */
            $cupon = app(CouponService::class)->resolver(
                $request->input('coupon_code'),
                $subtotal,
                (int) $request->user_id,
                (int) $business->busines_id,
            );

            $descuento = $cupon ? $cupon->descuentoPara($subtotal) : 0.0;

            $total = $subtotal + $domicilio - $descuento;

            /*
             * ¿Sigue valiendo lo que la app prometió?
             *
             * El servidor siempre calculó bien —ignora los precios que manda el
             * cliente y usa los suyos— pero nadie comparaba, así que un cambio
             * de precio o de tarifa entre armar el carrito y tocar "Pagar" se
             * cobraba en silencio: la pantalla decía $18.400 y el pedido salía
             * por $21.900.
             *
             * Se responde 409 con el desglose real y NO se crea el pedido. Un
             * pedido por un importe que el usuario no aceptó es peor que un
             * pedido no creado: con el 409 la app puede enseñar la diferencia y
             * dejar que decida.
             *
             * El margen de un peso es por el redondeo de la app al pintar.
             */
            $esperado = $request->input('expected_total');

            if ($esperado !== null && abs((float) $esperado - $total) > 1) {
                DB::rollBack();

                return response()->json([
                    'message'  => 'El precio cambió mientras armabas el pedido.',
                    'expected' => (float) $esperado,
                    'total'    => $total,
                    'breakdown' => [
                        'subtotal'  => $subtotal,
                        'delivery'  => $domicilio,
                        'discount'  => $descuento,
                    ],
                ], 409);
            }

            // Del domicilio, una porción es del domiciliario y el resto de la
            // plataforma. Se congela acá: si la comisión cambia después, esta
            // orden conserva lo que se pactó al crearla.
            $domiciliaryFee = round(
                $domicilio * (float) Ajustes::valor('operacion.reparto_domiciliario')
            );

            $order = OrdersSales::create([
                'buyer_id' => $buyer->buyer_id,
                'busines_id' => $business->busines_id,
                'address_id' => $address?->address_id,
                'methods_id' => $request->methods_id,
                'total' => $total,
                'subtotal' => $subtotal,
                'domicilio' => $domicilio,
                'discount' => $descuento,
                'coupon_id' => $cupon?->id,
                'domiciliary_fee' => $domiciliaryFee,
                'sale_date' => now(),
                'delivery_date' => $isScheduled ? $deliveryDate : now(),
                'is_scheduled' => $isScheduled,
                'pickup' => $isPickup,
                'pickup_time' => $isPickup && $request->pickup_time
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
                    'unit_price' => (float) $prices[$p['product_id']],
                ]);
            }

            // El uso se consume ya con la orden creada, dentro de la misma
            // transacción: si algo falla más abajo, el cupón se libera solo.
            if ($cupon) {
                app(CouponService::class)->canjear(
                    $cupon,
                    (int) $request->user_id,
                    (int) $order->orderSales_id,
                    $descuento,
                );
            }

            /* ========= PAGO ONLINE ========= */

            if (in_array($request->methods_id, [2, 5])) {

                $paymentController = app(PaymentController::class);

                // 1️⃣ Crear intent
                $intent = $paymentController->createIntent($order, $bold);

                // 2️⃣ Payer: si la app no lo manda, se construye desde el
                //    perfil del comprador con el formato EXACTO que exige
                //    Bold (person_type, document y billing_address son
                //    obligatorios). Mismos fallbacks que usa dev97.
                $payer = $request->payer;

                if (!$payer) {
                    $payerUser = $buyer->user;
                    $phone = $payerUser->phone ?? '3000000000';
                    $addressStr = $address->address ?? 'Calle 1';
                    $city = $address?->municipality?->name ?? 'Barranquilla';
                    $province = $address?->municipality?->department?->name ?? 'Atlántico';

                    $payer = [
                        'person_type' => 'NATURAL_PERSON',
                        'name' => $payerUser->name ?? 'Cliente',
                        'phone' => $phone,
                        'email' => $payerUser->email ?? 'correo@ejemplo.com',
                        'document_type' => 'CEDULA',
                        'document_number' => '1234567890',
                        'billing_address' => [
                            'street1' => $addressStr,
                            'street2' => '',
                            'city' => $city,
                            'zip_code' => '110111',
                            'province' => $province,
                            'country' => 'CO',
                            'phone' => $phone,
                        ],
                    ];
                }

                // 3️⃣ Método de pago
                $paymentMethod = $request->methods_id == 2
                    ? array_merge(['name' => 'CREDIT_CARD'], $request->payment_method)
                    : [
                        'name' => 'QR',
                        'qr_format' => 'BOLD_BASE64' //CLAVE puede ser ese o TEXT o BASE64
                    ];

                // 4️⃣ Productos (con los precios reales del servidor)
                $products = collect($request->products)->map(fn($p) => [
                    'product_id' => $p['product_id'],
                    'amount' => (int) $p['amount'],
                    'unit_price' => (float) $prices[$p['product_id']],
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
                // La app lee el QR / redirect de acá para el pago online
                'action' => isset($payment) ? [
                    'qr_payload' => $payment->qr_payload ?? null,
                    'redirect_url' => $payment->redirect_url ?? null,
                ] : null,
            ], 201);
        } catch (\RuntimeException $e) {
            DB::rollBack();

            /*
             * Rechazos de cupón (vencido, agotado, tope por persona). El mensaje
             * ya viene redactado para quien pide y va tal cual: dejarlo caer en
             * el catch de abajo lo convertiría en "Error al crear la orden" y el
             * usuario reintentaría el mismo código sin saber qué pasó.
             */
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            DB::rollBack();

            // Errores de configuración del comercio en Bold → mensaje claro
            $message = str_contains($e->getMessage(), 'MCFG_004')
                || str_contains($e->getMessage(), 'Not Available')
                ? 'Este método de pago no está disponible por el momento. Intenta con otro método.'
                : 'Error al crear la orden';

            return response()->json([
                'message' => $message,
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

            /*
             * Reglas de asignación. Se validan acá y no en la app porque por
             * esta misma transición pasan los dos caminos: el domiciliario
             * aceptando un pedido y el tendero despachándoselo. Si viviera
             * solo en el cliente, cualquiera de los dos podría saltársela.
             */
            if (!$domiciliary->available) {
                return response()->json([
                    'message' => 'El domiciliario no está disponible en este momento.',
                    'reason'  => 'unavailable',
                ], 422);
            }

            $maxSimultaneos = (int) Ajustes::valor('operacion.entregas_simultaneas');

            $enCurso = OrdersSales::where('domiciliary_id', $domiciliary->domiciliary_id)
                ->where('state', 3)
                ->where('orderSales_id', '!=', $order->orderSales_id)
                ->count();

            if ($enCurso >= $maxSimultaneos) {
                return response()->json([
                    'message' => "Ya hay {$enCurso} pedidos en curso. Se debe entregar alguno antes de aceptar otro.",
                    'reason'  => 'limit_reached',
                    'active_orders' => $enCurso,
                    'max_active_orders' => $maxSimultaneos,
                ], 422);
            }

            $order->state = 3;
            $order->domiciliary_id = $domiciliary->domiciliary_id;
            // Ancla del cronómetro de entrega en la app
            $order->dispatched_at = now();

            // Pedido entregado
        } elseif ($order->state == 3 && $request->state == 4) {
            $order->state = 4;
            $order->delivery_date = now();

            /*
             * Pago en efectivo: se registra al entregar, porque es cuando el
             * domiciliario recibe la plata.
             *
             * Los importes salen de la orden, no se recalculan acá. Antes el
             * domicilio estaba quemado en 2000 (ignorando la tarifa real y los
             * pedidos pickup), el total restaba el domicilio en vez de sumarlo
             * y `amount` estaba fijo en 1.
             */
            if ($order->methods_id == 1) {
                $valorPromocion = 0; // pendiente: descuentos y promociones

                Payment::create([
                    'orderSales_id' => $order->orderSales_id,
                    'methods_id' => $order->methods_id,
                    'forms_id' => $order->forms_id,
                    'amount' => $order->total,
                    'subtotal' => $order->subtotal,
                    'total' => $order->total - $valorPromocion,
                    'domicilio' => $order->domicilio,
                    'domiciliary_fee' => $order->domiciliary_fee,
                    'valor_promocion' => $valorPromocion,
                    // `status` es NOT NULL y sin default: no enviarlo hacía
                    // fallar el insert, así que los pedidos en efectivo nunca
                    // llegaron a registrar pago (y el domiciliario no cobraba).
                    'provider' => 'cash',
                    'status' => 'approved',
                    'payment_status' => 1, // pagado
                    'payment_date' => now(),
                    'state' => 1 // activo
                ]);

                // El pago quedaba registrado y aprobado, pero la orden seguía
                // diciendo 'pending' para siempre: nadie sincronizaba este
                // campo al cobrar en efectivo. Resultado: pedidos entregados y
                // cobrados que en los tableros aparecían como pendientes de
                // pago, contradiciendo a la tabla de pagos.
                $order->payment_state = 'paid';
            }
        } else {
            return response()->json(['message' => 'Transición de estado no permitida.'], 400);
        }

        $order->save();

        /*
         * Y se avisa al teléfono del comprador.
         *
         * Hasta ahora este cambio no salía de la base: no había evento, y la
         * app tampoco sondeaba ni recargaba al volver a la pantalla, así que el
         * seguimiento se quedaba clavado en "pedido recibido" hasta que la
         * persona cerraba la app entera. El domiciliario llegaba a la puerta
         * mientras la pantalla decía que la tienda seguía preparando.
         *
         * Va después del save y no interrumpe: si el servidor de websockets
         * está caído, el pedido ya avanzó y la app tiene su propio respaldo
         * recargando la lista.
         */
        try {
            broadcast(new OrderStatusUpdated($order));
        } catch (\Throwable $e) {
            Log::warning('No se pudo anunciar el cambio de estado del pedido', [
                'order_id' => $order->orderSales_id,
                'error'    => $e->getMessage(),
            ]);
        }

        /*
         * Comprobante del pedido entregado.
         *
         * Va DESPUÉS de guardar y dentro de un try: la entrega ya ocurrió y no
         * puede deshacerse porque falle el comprobante. Si algo sale mal queda
         * en el registro y `facturas:emitir` lo recupera después — al revés, un
         * domiciliario se quedaría sin poder cerrar su entrega por un problema
         * de papeleo.
         *
         * `emitirPara` es idempotente, así que un reintento del cliente o un
         * doble toque no producen dos comprobantes del mismo pedido.
         */
        if ((int) $order->state === 4) {
            try {
                app(FacturaService::class)->emitirPara((int) $order->orderSales_id);
            } catch (\Throwable $e) {
                Log::warning('No se pudo emitir el comprobante del pedido ' . $order->orderSales_id, [
                    'error' => $e->getMessage(),
                ]);
            }
        }

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
        /*
         * Solo los pedidos de quien pregunta. La consulta no filtraba por
         * comprador, así que devolvía TODOS los pedidos entregados del
         * sistema: a cualquiera se le pedía calificar compras ajenas (y de
         * paso se le exponía la dirección de entrega de otras personas).
         */
        $buyer = Buyer::where('user_id', $request->user()->user_id)->first();

        if (!$buyer) {
            return response()->json([
                'message' => 'Órdenes pendientes de review',
                'orders' => [],
            ]);
        }

        $orders = OrdersSales::where('buyer_id', $buyer->buyer_id)
            ->where('state', 4)
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
