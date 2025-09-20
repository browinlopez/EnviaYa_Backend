<?php

namespace App\Http\Controllers\Order;

use App\Events\DomiciliaryLocationUpdated;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Buyer\Buyer;
use App\Models\Domiciliary;
use App\Models\Order\OrderGeolocation;
use App\Models\Order\OrdersSales;
use App\Models\Order\OrdersSalesDetail;
use App\Models\Payment\Payment;
use App\Models\Payment\PaymentForms;
use App\Models\Payment\PaymentMethods;
use App\Models\Product\ProductBusiness;
use App\Models\User;
use App\Models\User\UserAddress;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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
                'delivery_address' => $order->address ? [
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
                'total' => $order->total,
                'sale_date' => $order->sale_date,
                'state' => $order->state,

                'buyer' => $order->buyer ? [
                    'buyer_id' => $order->buyer->buyer_id,
                    'qualification' => $order->buyer->qualification,
                    'state' => $order->buyer->state,
                    'user' => $order->buyer->user ? [
                        'user_id' => $order->buyer->user->user_id,
                        'name' => $order->buyer->user->name,
                        'email' => $order->buyer->user->email,
                    ] : null,
                ] : null,

                /*             // Reutilizamos businessData
            'business' => $businessData, */

                'delivery_address' => $order->address ? [
                    'address_id' => $order->address->address_id,
                    'address' => $order->address->address,
                    'alias' => $order->address->alias?->name,
                    'municipality' => $order->address->municipality?->name,
                    'department' => $order->address->department?->name,
                    'country' => $order->address->country?->name,
                    'latitude' => $order->address->latitude,
                    'longitude' => $order->address->longitude,
                ] : null,

                'details' => $order->details->map(function ($detail) {
                    return [
                        'product_id' => $detail->product->products_id,
                        'name' => $detail->product->name,
                        'description' => $detail->product->description,
                        'category_id' => $detail->product->category_id,
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
        // Solo valida business_id
        $request->validate([
            'business_id' => 'required|integer|exists:business,busines_id',
        ]);

        $business_id = $request->business_id;

        // --- Fechas actuales (sin parámetros) ---
        $week_start = now()->startOfWeek();
        $week_end   = (clone $week_start)->endOfWeek();

        $month_start = now()->startOfMonth();
        $month_end   = (clone $month_start)->endOfMonth();

        /**
         * Ingresos semanales
         */
        $incomeWeek = Payment::select(
            DB::raw('DAYOFWEEK(payment_date) as weekday'),
            DB::raw('SUM(total) as total_income')
        )
            ->whereHas('order', function ($q) use ($business_id) {
                $q->where('busines_id', $business_id);
            })
            ->whereBetween('payment_date', [$week_start->toDateString(), $week_end->toDateString()])
            ->groupBy('weekday')
            ->get()
            ->keyBy('weekday');

        // Mapear días de la semana
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
         * Ingresos mensuales
         */
        $incomeMonth = Payment::whereHas('order', function ($q) use ($business_id) {
            $q->where('busines_id', $business_id);
        })
            ->whereBetween('payment_date', [$month_start->toDateString(), $month_end->toDateString()])
            ->sum('subtotal');

        /**
         * Total histórico
         */
        $incomeTotal = Payment::whereHas('order', function ($q) use ($business_id) {
            $q->where('busines_id', $business_id);
        })
            ->sum('subtotal');

        return response()->json([
            'business_id'     => $business_id,
            'week_start'      => $week_start->toDateString(),
            'week_end'        => $week_end->toDateString(),
            'weekly_income'   => $weeklyIncome,
            'month_start'     => $month_start->toDateString(),
            'month_end'       => $month_end->toDateString(),
            'monthly_income'  => (float)$incomeMonth,
            'total_income'    => (float)$incomeTotal
        ]);
    }

    // Crear orden de venta
    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'busines_id' => 'required|integer',
            'address_id' => 'required|integer|exists:user_address,address_id',
            'products' => 'required|array',
            'products.*.product_id' => 'required|integer',
            'products.*.amount' => 'required|integer',
            'products.*.unit_price' => 'required|numeric',
            'methods_id' => 'required|integer|exists:payment_methods,methods_id',
            'forms_id' => 'nullable|integer|exists:payment_forms,forms_id',
        ]);

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
            $total = collect($request->products)->sum(fn($p) => $p['amount'] * $p['unit_price']);

            $order = OrdersSales::create([
                'buyer_id' => $buyer->buyer_id,
                'busines_id' => $business->busines_id,
                'address_id' => $address->address_id,
                'methods_id' => $request->methods_id,
                'forms_id' => $request->forms_id,
                'total' => $total,
                'sale_date' => now(),
                'state' => 1
            ]);

            $outOfStockProducts = [];

            foreach ($request->products as $product) {
                $productBusiness = ProductBusiness::where('busines_id', $request->busines_id)
                    ->where('products_id', $product['product_id'])
                    ->first();

                if (!$productBusiness) {
                    throw new \Exception("El producto ID {$product['product_id']} no pertenece al negocio");
                }

                $amountToRegister = $product['amount'];

                // Si no hay suficiente stock
                if ($productBusiness->amount < $product['amount']) {
                    $outOfStockProducts[] = [
                        'name' => $productBusiness->product->name,
                        'missing' => $product['amount'] - $productBusiness->amount
                    ];
                    // Registrar con cantidad negativa que indica falta
                    $amountToRegister = $product['amount'] - $product['amount']; // o 0, depende cómo quieras mostrar
                }

                OrdersSalesDetail::create([
                    'orderSales_id' => $order->orderSales_id,
                    'product_id' => $product['product_id'],
                    'amount' => $amountToRegister,
                    'unit_price' => $product['unit_price']
                ]);

                // Reducir stock solo si hay disponible
                if ($productBusiness->amount > 0) {
                    $productBusiness->amount -= min($productBusiness->amount, $product['amount']);
                    $productBusiness->save();
                }
            }

            /**
             * 👉 Aquí creamos automáticamente el pago
             * Solo si methods_id != 1 (es decir, pago online u otro)
             */
            if ($order->methods_id != 1) {
                $subtotal = $total; // si tienes otro cálculo, lo reemplazas
                $domicilio = 2000; // aquí puedes calcular costo domicilio
                $valorPromocion = 0; // si tienes promociones
                $totalFinal = $subtotal + $domicilio - $valorPromocion;

                Payment::create([
                    'orderSales_id' => $order->orderSales_id,
                    'methods_id' => $order->methods_id,
                    'forms_id' => $order->forms_id,
                    'amount' => $totalFinal,
                    'subtotal' => $subtotal,
                    'total' => $totalFinal,
                    'domicilio' => $domicilio,
                    'valor_promocion' => $valorPromocion,
                    'payment_status' => 1, // por ejemplo 'pagado'
                    'payment_date' => now(),
                    'state' => 1 // activo
                ]);
            }

            DB::commit();

            // Cargamos relaciones necesarias
            $order->load('details.product.category', 'address.municipality.department.country', 'address.alias', 'business', 'promotions', 'payments');

            return response()->json([
                'message' => 'Orden creada',
                'order' => [
                    'order_id' => $order->orderSales_id,
                    'buyer_id' => $order->buyer_id,
                    'busines_id' => $order->busines_id,
                    'total' => $order->total,
                    'sale_date' => $order->sale_date,
                    'state' => $order->state,
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
                    'delivery_address' => $order->address ? [
                        'address_id' => $order->address->address_id,
                        'address' => $order->address->address,
                        'alias' => $order->address->alias?->name,
                        'municipality' => $order->address->municipality?->name,
                        'department' => $order->address->department?->name,
                        'country' => $order->address->country?->name,
                        'latitude' => $order->address->latitude !== null ? (float)$order->address->latitude : null,
                        'longitude' => $order->address->longitude !== null ? (float)$order->address->longitude : null,
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
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al crear la orden',
                'error' => $e->getMessage()
            ], 400);
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
                    'amount' => $total,
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

    /* public function updateLocation(Request $request)
    {
        $data = $request->validate([
            'domiciliary_id' => 'required|exists:domiciliary,domiciliary_id',
            'orderSales_id'  => 'required|exists:orderssales,orderSales_id',
            'latitude'       => 'required|numeric',
            'longitude'      => 'required|numeric', // antes era 'length'
            'state'          => 'nullable|integer'
        ]);

        $geo = OrderGeolocation::create($data);

        // Enviar la ubicación al servidor Node.js
        Http::post('http://192.168.20.29:3000/location', [
            'order_id'       => $geo->orderSales_id,
            'latitude'       => $geo->latitude,
            'longitude'      => $geo->longitude, // ahora es 'longitude'
            'domiciliary_id' => $geo->domiciliary_id,
        ]);

        return response()->json($geo);
    } */

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
}
