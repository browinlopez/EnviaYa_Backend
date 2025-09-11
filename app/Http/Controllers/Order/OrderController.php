<?php

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Buyer\Buyer;
use App\Models\Order\OrdersSales;
use App\Models\Order\OrdersSalesDetail;
use App\Models\Payment\PaymentForms;
use App\Models\Payment\PaymentMethods;
use App\Models\Product\ProductBusiness;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    // Función para obtener todas las órdenes de un usuario
    public function ordersUser(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer'
        ]);

        $user = User::find($request->user_id);

        if (!$user) {
            return response()->json([
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        $orders = OrdersSales::where('buyer_id', $user->user_id)
            ->with('details.product', 'business', 'promotions', 'payments')
            ->get();

        $formattedOrders = $orders->map(function ($order) {
            return [
                'order_id' => $order->orderSales_id,
                'delivery_address' => $order->delivery_address,
                'total' => $order->total,
                'sale_date' => $order->sale_date,
                'state' => $order->state,
                'business' => [
                    'business_id' => $order->business->busines_id,
                    'name' => $order->business->name,
                    'phone' => $order->business->phone,
                    'address' => $order->business->address,
                    'razon_social' => $order->business->razonSocial_DCD,
                    'NIT' => $order->business->NIT,
                    'logo' => $order->business->logo,
                    'city' => $order->business->city,
                    'state' => $order->business->state,
                ],
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

        $orders = OrdersSales::where('busines_id', $business->busines_id)
            ->with('details.product', 'buyer', 'promotions', 'payments')
            ->get();

        $formattedOrders = $orders->map(function ($order) {
            return [
                'order_id' => $order->orderSales_id,
                'delivery_address' => $order->delivery_address,
                'total' => $order->total,
                'sale_date' => $order->sale_date,
                'state' => $order->state,
                'buyer' => [
                    'user_id' => $order->buyer->user_id,
                    'name' => $order->buyer->name,
                    'email' => $order->buyer->email,
                    'qualification' => $order->buyer->qualification,
                    'state' => $order->buyer->state,
                ],
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
            'orders' => $formattedOrders
        ]);
    }
    
    public function weeklyIncomeBusiness(Request $request)
    {
        $request->validate([
            'business_id' => 'required|integer|exists:business,busines_id',
            'week_start' => 'nullable|date' // opcional, lunes de la semana, por defecto la semana actual
        ]);

        $business_id = $request->business_id;
        $week_start = $request->week_start ? Carbon::parse($request->week_start)->startOfWeek() : Carbon::now()->startOfWeek();
        $week_end = (clone $week_start)->endOfWeek();

        // Obtener ingresos agrupados por día
        $income = OrdersSales::select(
            DB::raw('DAYOFWEEK(sale_date) as weekday'),
            DB::raw('SUM(total) as total_income')
        )
            ->where('busines_id', $business_id)
            ->whereBetween('sale_date', [$week_start->toDateString(), $week_end->toDateString()])
            ->groupBy('weekday')
            ->get()
            ->keyBy('weekday');

        // Mapear a todos los días de la semana (1 = domingo, 2 = lunes, ... 7 = sábado)
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
            $weeklyIncome[$day] = $income[$key]->total_income ?? 0;
        }

        return response()->json([
            'business_id' => $business_id,
            'week_start' => $week_start->toDateString(),
            'week_end' => $week_end->toDateString(),
            'weekly_income' => $weeklyIncome
        ]);
    }

    // Crear orden de venta
    public function store(Request $request)
    {
        $request->validate([
            'buyer_id' => 'required|integer',
            'busines_id' => 'required|integer',
            'delivery_address' => 'required|string',
            'products' => 'required|array',
            'products.*.product_id' => 'required|integer',
            'products.*.amount' => 'required|integer',
            'products.*.unit_price' => 'required|numeric',
            'methods_id' => 'required|integer|exists:payment_methods,methods_id',
            'forms_id' => 'required|integer|exists:payment_forms,forms_id',
        ]);

        $buyer = Buyer::find($request->buyer_id);
        if (!$buyer) {
            return response()->json(['message' => 'Usuario comprador no encontrado'], 404);
        }

        $business = Business::find($request->busines_id);
        if (!$business) {
            return response()->json(['message' => 'Negocio no encontrado'], 404);
        }

        DB::beginTransaction();

        try {
            $order = OrdersSales::create([
                'buyer_id' => $buyer->buyer_id,
                'busines_id' => $business->busines_id,
                'delivery_address' => $request->delivery_address,
                'methods_id' => $request->methods_id,
                'forms_id' => $request->forms_id,
                'total' => collect($request->products)->sum(fn($p) => $p['amount'] * $p['unit_price']),
                'sale_date' => now(),
                'state' => 1
            ]);

            foreach ($request->products as $product) {
                $productBusiness = ProductBusiness::where('busines_id', $request->busines_id)
                    ->where('products_id', $product['product_id'])
                    ->first();

                if (!$productBusiness) {
                    throw new \Exception("El producto ID {$product['product_id']} no pertenece al negocio");
                }

                if ($productBusiness->amount < $product['amount']) {
                    throw new \Exception("Cantidad insuficiente para el producto ID {$product['product_id']}. Disponible: {$productBusiness->amount}");
                }

                OrdersSalesDetail::create([
                    'orderSales_id' => $order->orderSales_id,
                    'product_id' => $product['product_id'],
                    'amount' => $product['amount'],
                    'unit_price' => $product['unit_price']
                ]);

                $productBusiness->amount -= $product['amount'];
                $productBusiness->save();
            }

            DB::commit();

            return response()->json([
                'message' => 'Orden creada',
                'order' => $order->load('details.product')
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
        $methods = PaymentMethods::with('forms')->where('state', true)->get();
        return response()->json($methods);
    }

    // Obtener formas de pago
    public function paymentForms()
    {
        $forms = PaymentForms::with('methods')->where('state', true)->get();
        return response()->json($forms);
    }

    // Actualizar estado de la orden (números)
    public function updateStatus(Request $request)
    {
        $request->validate([
            'order_id' => 'required|integer',
            'state' => 'required|integer|in:1,2,3'
        ]);

        $order = OrdersSales::find($request->order_id);

        if (!$order) {
            return response()->json([
                'message' => 'Orden no encontrada'
            ], 404);
        }

        $order->state = $request->state;
        $order->save();

        return response()->json([
            'message' => 'Estado de la orden actualizado',
            'order' => $order->load('details.product', 'buyer', 'business')
        ]);
    }
}
