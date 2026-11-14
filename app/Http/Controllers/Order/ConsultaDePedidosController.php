<?php

declare(strict_types=1);

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\IngresosDelNegocioRequest;
use App\Http\Requests\Order\PedidosDelNegocioRequest;
use App\Http\Requests\Order\PedidosDelUsuarioRequest;
use App\Http\Requests\Order\UltimosPedidosRequest;
use App\Http\Resources\Negocio\NegocioDelPedidoResource;
use App\Http\Resources\Pedido\PedidoDeLaAppResource;
use App\Http\Resources\Pedido\PedidoDelCompradorResource;
use App\Http\Resources\Pedido\PosicionDelDomiciliarioResource;
use App\Models\Business;
use App\Models\Buyer\Buyer;
use App\Models\Order\OrderGeolocation;
use App\Models\Order\OrdersSales;
use App\Models\User;
use App\Services\CreditoDeTienda;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ConsultaDePedidosController extends Controller
{
    use ComprobarPertenencia;

    // Función para obtener todas las órdenes de un usuario
    public function ordersUser(PedidosDelUsuarioRequest $request)
    {

        $buyer = Buyer::where('user_id', $request->user_id)->first();

        if (! $buyer) {
            return response()->json([
                'message' => 'Usuario comprador no encontrado',
            ], 404);
        }

        $orders = OrdersSales::where('buyer_id', $buyer->buyer_id)
            ->confirmados()
            ->with('details.product.category', 'business.municipality', 'promotions', 'payments', 'address.municipality.department.country', 'address.alias', 'domiciliary.user')
            ->get();

        $formattedOrders = $orders->map(
            fn ($order) => (new PedidoDelCompradorResource($order))->resolve(),
        );

        return response()->json([
            'message' => 'Órdenes encontradas',
            'orders' => $formattedOrders,
        ]);
    }

    // Función para obtener todas las órdenes de un negocio (tendero)
    public function ordersBusiness(PedidosDelNegocioRequest $request)
    {

        /*
         * Un identificador en el cuerpo es una sugerencia, no una
         * credencial: son correlativos. Sin esto, con la cuenta de un
         * comprador se leian los pedidos de cualquier tienda.
         */
        if ($no = $this->negarNegocioAjeno($request, $request->business_id)) {
            return $no;
        }

        $business = Business::find($request->business_id);

        if (! $business) {
            return response()->json([
                'message' => 'Negocio no encontrado',
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
        /*
         * EL TABLERO ES LO DE AHORA, NO EL ARCHIVO.
         *
         * Esta consulta traía TODOS los pedidos confirmados del negocio desde
         * que abrió. Mientras la tienda tenía diez no se notaba; con una que
         * vende, la respuesta crece sin tope y el tablero acaba pintando
         * entregas de hace meses bajo un rótulo que dice «hoy».
         *
         * Los abiertos —sin aceptar, preparando, en camino— van completos: un
         * pedido sin aceptar de hace seis meses SIGUE sin aceptar y esconderlo
         * por viejo sería esconder el problema. Los cerrados —entregado y
         * cancelado— se acotan al día, que es lo que el tendero mira ahí: si
         * le pagaron y si hace falta el comprobante. El resto vive en el
         * historial, que tiene su propia pantalla y su filtro de fechas.
         */
        $orders = OrdersSales::where('busines_id', $business->busines_id)
            ->confirmados()
            ->where(function ($q) {
                $q->whereIn('state', [1, 2, 3])
                  /*
                   * Los cerrados, por el día en que SE CERRARON y no por el
                   * día en que se pidieron: uno pedido a las 23:50 y
                   * entregado a las 00:20 es de hoy y no aparecía. El
                   * entregado tiene su fecha; el cancelado, la de su último
                   * cambio.
                   */
                    ->orWhere(fn ($q2) => $q2->where('state', 4)
                        ->where($this->cerradoHoy('delivery_date')))
                    ->orWhere(fn ($q2) => $q2->where('state', 5)
                        ->where($this->cerradoHoy('updated_at')));
            })
            // La lista la declara el presentador, que es quien las lee: esta
            // copia se habia quedado sin `domiciliary.user` y cargaba el
            // repartidor de uno en uno.
            ->with(PedidoDeLaAppResource::relaciones())
            ->get();

        // Datos del business, solo una vez
        $businessData = (new NegocioDelPedidoResource($business))->resolve();

        $formattedOrders = $orders->map(
            fn ($order) => (new PedidoDeLaAppResource($order))->resolve()
        );

        return response()->json([
            'message' => 'Órdenes del negocio encontradas',
            'business' => $businessData,  // incluimos business una sola vez
            'orders' => $formattedOrders,
        ]);
    }

    public function incomeBusiness(IngresosDelNegocioRequest $request)
    {

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
        $incomeOrders = fn () => OrdersSales::where('busines_id', $business_id)
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

        /*
         * El día de la semana, sin atarse a MySQL.
         *
         * `DAYOFWEEK()` no existe en SQLite, que es sobre lo que corre la
         * suite: esta pantalla no se podía probar y por eso llevaba sin
         * prueba desde que se escribió. Las dos expresiones numeran igual
         * —1 es domingo y 7 sábado—, así que `$daysOfWeek` no cambia.
         */
        $diaDeLaSemana = DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%w', sale_date) AS INTEGER) + 1"
            : 'DAYOFWEEK(sale_date)';

        // Ingresos por día de la semana para un rango dado
        $incomeByDay = function ($start, $end) use ($incomeOrders, $daysOfWeek, $diaDeLaSemana) {
            $rows = $incomeOrders()
                ->select(
                    DB::raw("$diaDeLaSemana as weekday"),
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
        $currentWeekEnd = (clone $currentWeekStart)->endOfWeek();
        $previousWeekStart = (clone $currentWeekStart)->subWeek();
        $previousWeekEnd = (clone $previousWeekStart)->endOfWeek();

        $weeklyIncome = $incomeByDay($currentWeekStart, $currentWeekEnd);
        $previousWeeklyIncome = $incomeByDay($previousWeekStart, $previousWeekEnd);

        $currentWeekTotal = array_sum($weeklyIncome);
        $previousWeekTotal = array_sum($previousWeeklyIncome);

        $difference = $currentWeekTotal - $previousWeekTotal;
        $percentageChange = $previousWeekTotal > 0
            ? ($difference / $previousWeekTotal) * 100
            : 100;

        /*
         * CÓMO LE PAGARON ESTA SEMANA.
         *
         * No es una curiosidad: al tendero le cambia la semana que el 60 %
         * entre en efectivo —plata que ya tiene en el cajón— o por la app
         * —plata que le liquidan después—. Y el fiado es el que decide si
         * puede seguir fiando. El panel enseñaba el total y no de dónde
         * salía.
         *
         * Se agrupa en TRES y no en los siete métodos que existen, porque
         * son tres bolsillos distintos y los siete no: el 1 es efectivo —ya
         * está en el cajón—, el 7 es el crédito de la tienda —todavía se lo
         * deben— y del 2 al 6 son tarjetas, QR y transferencias, que para
         * el tendero son lo mismo: plata que le liquida la plataforma.
         */
        $bolsillo = fn (int $metodo) => match (true) {
            $metodo === 1 => 'Efectivo',
            $metodo === CreditoDeTienda::METODO => 'Fiado',
            default => 'Pagado en la app',
        };

        $porMedio = $incomeOrders()
            ->select('methods_id', DB::raw('SUM(total) as total'))
            ->whereBetween('sale_date', [$currentWeekStart, $currentWeekEnd])
            ->groupBy('methods_id')
            ->get()
            ->groupBy(fn ($f) => $bolsillo((int) $f->methods_id))
            ->map(fn ($g) => (float) $g->sum('total'))
            ->sortDesc()
            ->map(fn ($total, $medio) => ['medio' => $medio, 'total' => $total])
            ->values()
            ->all();

        $month_start = now()->startOfMonth();
        $month_end = (clone $month_start)->endOfMonth();

        $monthlyIncome = $incomeOrders()
            ->whereBetween('sale_date', [$month_start, $month_end])
            ->sum('total');

        $totalIncome = $incomeOrders()->sum('total');

        /* Lo que la pantalla del panel necesita además de los totales: cuántas
           ventas fueron, el ticket promedio, lo de hoy y la tendencia. */
        $ventasSemana = $incomeOrders()->whereBetween('sale_date', [$currentWeekStart, $currentWeekEnd]);
        $cuantasSemana = (clone $ventasSemana)->count();
        $hoy = $incomeOrders()->whereBetween('sale_date', [now()->startOfDay(), now()->endOfDay()]);

        $ultimasSemanas = collect(range(7, 0))->map(function ($atras) use ($incomeOrders, $currentWeekStart) {
            $inicio = (clone $currentWeekStart)->subWeeks($atras);
            $fin = (clone $inicio)->endOfWeek();

            return [
                'inicio' => $inicio->toDateString(),
                'total' => (float) $incomeOrders()->whereBetween('sale_date', [$inicio, $fin])->sum('total'),
            ];
        })->values()->all();

        return response()->json([
            'business_id' => $business_id,

            'week_start' => $currentWeekStart->toDateString(),
            'week_end' => $currentWeekEnd->toDateString(),
            // Las de la semana pasada: el panel enseñaba las de ESTA bajo el
            // rótulo «Semana pasada» porque no tenía otras.
            'previous_week_start' => $previousWeekStart->toDateString(),
            'previous_week_end' => $previousWeekEnd->toDateString(),

            /* LA SEMANA PASADA A ESTA MISMA ALTURA: de su lunes hasta el mismo
               día y hora de hoy. Comparar el miércoles contra una semana
               entera siempre da «vas peor»; contra esto, la comparación es
               justa. */
            'previous_week_to_date' => (float) $incomeOrders()
                ->whereBetween('sale_date', [$previousWeekStart, now()->subWeek()])
                ->sum('total'),

            'ventas_semana' => $cuantasSemana,
            'ticket_promedio' => $cuantasSemana ? round((float) $currentWeekTotal / $cuantasSemana, 2) : 0.0,
            'hoy' => (float) (clone $hoy)->sum('total'),
            'ventas_hoy' => (clone $hoy)->count(),
            'ultimas_semanas' => $ultimasSemanas,

            'weekly_income' => $weeklyIncome,
            // Semana anterior desglosada por día (antes solo venía el total
            // y la línea comparativa del chart no tenía datos)
            'previous_weekly_income' => $previousWeeklyIncome,

            'current_week_income' => (float) $currentWeekTotal,
            'previous_week_income' => (float) $previousWeekTotal,
            'difference' => (float) $difference,
            'percentage_change' => round($percentageChange, 2),

            'month_start' => $month_start->toDateString(),
            'month_end' => $month_end->toDateString(),
            'monthly_income' => (float) $monthlyIncome,

            'total_income' => (float) $totalIncome,

            // Cómo le pagaron esta semana, de mayor a menor.
            'por_medio' => $porMedio,
        ]);
    }

    /**
     * DONDE VA UN DOMICILIARIO, Y QUIEN PUEDE PREGUNTARLO.
     *
     * Esto solo validaba que el `domiciliary_id` existiera. Los identificadores
     * son correlativos, asi que CUALQUIER cuenta con sesion podia pedir la
     * ultima posicion de CUALQUIER domiciliario de la plataforma probando
     * numeros: donde vive, por donde anda, a que hora. Es la clase de dato que
     * no se arregla despues de que salga.
     *
     * Ahora solo pasan tres: el propio domiciliario, el negocio al que reparte
     * --el tendero necesita saber donde va su flota-- y el comprador que tiene
     * un pedido en curso con el. El administrador pasa por su propia puerta,
     * igual que en el resto del proyecto.
     *
     * Un desconocido recibe 403, y no una respuesta vacia: decir «no hay
     * posicion» a quien no deberia estar preguntando es confirmarle que el
     * numero existe.
     */
    public function latest(UltimosPedidosRequest $request)
    {
        $data = $request->validated();

        /* La regla vive en `DomiciliaryPolicy::verUbicacion`, por la puerta
           con nombre que la registra: §08 pide Policy o Gate por recurso, y
           esto era un metodo privado de este controlador. */
        abort_unless(
            Gate::allows('ver-ubicacion-del-domiciliario', [(int) $data['domiciliary_id']]),
            403,
            'Esa posicion no es tuya.',
        );

        $last = OrderGeolocation::where('domiciliary_id', $data['domiciliary_id'])
            ->orderByDesc('created_at')
            ->first();

        if ($last) {
            return response()->json(
                (new PosicionDelDomiciliarioResource($last))->resolve()
            );
        }

        return response()->json([]); // sin ubicación
    }

    /**
     * «Se cerró hoy», mirando esa columna y cayendo a `sale_date` si está vacía.
     *
     * SUSTITUYE A `whereDate(DB::raw('COALESCE($columna, sale_date)'), hoy)`,
     * que es lo que había, y la reescritura importa tanto como los índices que
     * la acompañan. Esta es la secuencia de medidas sobre la base de
     * desarrollo, y las dos primeras conclusiones fueron equivocadas:
     *
     *   1. como estaba                       type=ref    504 filas
     *   2. sargable, SIN los índices nuevos  type=ref    504 filas  <-- nada
     *   3. con los índices, SIN reescribir   type=ref    504 filas  <-- nada
     *   4. las dos cosas juntas              type=range   69 filas
     *
     * O sea que el tablero del tendero —la pantalla que se queda puesta y se
     * refresca sola— abría las 504 filas del histórico del negocio en CADA
     * refresco, y seguiría haciéndolo con cualquiera de los dos arreglos por
     * separado. Con los dos, abre las de hoy.
     *
     * LA EQUIVALENCIA NO SE DEDUJO, SE COMPROBÓ: 280 combinaciones de negocio
     * y día contra la base de desarrollo, las dos formas dan el mismo número
     * en las 280.
     *
     * Medio abierto —`>=` la medianoche y `<` la siguiente— y no `between`
     * hasta `endOfDay()`: el final del día son las 23:59:59.999999 y perder el
     * borde por microsegundos es la clase de fallo que aparece una vez al año.
     *
     * La rama del `IS NULL` conserva el `COALESCE`: un pedido entregado sin
     * `delivery_date` cuenta por su `sale_date`, igual que antes. Va dentro de
     * su propio grupo para que el `OR` no se escape del `AND state = 4`.
     */
    private function cerradoHoy(string $columna): callable
    {
        $desde = now()->startOfDay();
        $hasta = now()->addDay()->startOfDay();

        return function ($q) use ($columna, $desde, $hasta) {
            $q->where(fn ($r) => $r->where($columna, '>=', $desde)
                ->where($columna, '<', $hasta))
                ->orWhere(fn ($r) => $r->whereNull($columna)
                    ->where('sale_date', '>=', $desde)
                    ->where('sale_date', '<', $hasta));
        };
    }
}
