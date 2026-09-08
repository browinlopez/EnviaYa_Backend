<?php

namespace App\Http\Controllers\Order;

use App\Services\PedidoParaLaApp;
use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Services\Ajustes;
use App\Services\PoliticaDeDomicilio;
use App\Services\CustodiaDeEfectivo;
use App\Services\ConfirmacionDePago;
use App\Services\Avisos;
use App\Events\DomiciliaryLocationUpdated;
use App\Events\OrderCreated;
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

class ConsultaDePedidosController extends Controller
{
    public function __construct(private readonly PedidoParaLaApp $presentador)
    {
    }

    use ComprobarPertenencia;

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
            ->confirmados()
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
                // Compromiso y cumplimiento del plazo. Se mandan resueltos
                // desde acá para que la app no tenga que reimplementar la
                // regla y contarla distinto que el informe del panel.
                'promised_minutes' => $order->promised_minutes,
                'delivery_minutes' => $order->delivery_minutes,
                'on_time' => $order->on_time,
                'delay_minutes' => $order->delay_minutes,
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
                    /*
                     * La calle va JUNTO a la dirección del mapa, no en vez de
                     * ella.
                     *
                     * La del mapa sitúa la cuadra; la que escribió la persona
                     * lleva el número de casa o apartamento. El domiciliario
                     * necesita las dos para llegar a la puerta, y hasta ahora
                     * solo le llegaba la primera.
                     */
                    'address' => trim(implode(', ', array_filter([
                        $order->address->street,
                        $order->address->address,
                    ]))),
                    'street' => $order->address->street,
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

        /*
         * Un identificador en el cuerpo es una sugerencia, no una
         * credencial: son correlativos. Sin esto, con la cuenta de un
         * comprador se leian los pedidos de cualquier tienda.
         */
        if ($no = $this->negarNegocioAjeno($request, $request->business_id)) {
            return $no;
        }

        $business = Business::find($request->business_id);

        if (!$business) {
            return response()->json([
                'message' => 'Negocio no encontrado'
            ], 404);
        }

        // Precargamos relaciones necesarias
        /*
         * Sin los que esperan un pago en línea.
         *
         * No filtraba nada: un pago rechazado dejaba el pedido en la lista de
         * la tienda —y con aviso en vivo—, así que el tendero podía ponerse a
         * preparar comida que nadie pagó. Y cada reintento dejaba otro.
         */
        $orders = OrdersSales::where('busines_id', $business->busines_id)
            ->confirmados()
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
        $businessData = $this->presentador->negocio($business);

        $formattedOrders = $orders->map(
            fn ($order) => $this->presentador->formatear($order)
        );

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

        /*
         * Un identificador en el cuerpo es una sugerencia, no una
         * credencial: son correlativos. Sin esto, con la cuenta de un
         * comprador se leian los pedidos de cualquier tienda.
         */
        if ($no = $this->negarNegocioAjeno($request, $request->business_id)) {
            return $no;
        }

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
