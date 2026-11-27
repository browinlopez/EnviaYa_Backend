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
use App\Queries\VentasDelNegocio;
use App\Services\CreditoDeTienda;
use Illuminate\Support\Carbon;
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
            ->with('details.product.category', 'business.municipality', 'promotions', 'payments', 'address.municipality.department.country', 'address.department', 'address.alias', 'domiciliary.user')
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
        /*
         * CON PERIODO (el panel), todo se acota a él: lo que entró desde el
         * inicio del día, de la semana o del mes, y lo cerrado en ese mismo
         * rango. Así el tablero no acumula pedidos viejos. Sin periodo (la
         * app), lo de siempre: lo abierto entero y lo cerrado de hoy.
         */
        $desde = match ($request->input('periodo')) {
            'hoy' => now()->startOfDay(),
            'semana' => now()->startOfWeek(),
            'mes' => now()->startOfMonth(),
            default => null,
        };
        $hasta = match ($request->input('periodo')) {
            'hoy' => now()->addDay()->startOfDay(),
            'semana' => now()->endOfWeek()->addSecond(),
            'mes' => now()->endOfMonth()->addSecond(),
            default => null,
        };

        // Un rango a mano manda sobre el periodo: del inicio de `desde` al
        // final de `hasta` (o de ese mismo día si no se dice).
        if ($request->filled('desde')) {
            $desde = Carbon::parse($request->input('desde'))->startOfDay();
            $hasta = Carbon::parse($request->input('hasta') ?? $request->input('desde'))
                ->addDay()->startOfDay();
        }

        $orders = OrdersSales::where('busines_id', $business->busines_id)
            ->confirmados()
            ->where(function ($q) use ($desde, $hasta) {
                $q->where(function ($a) use ($desde, $hasta) {
                    $a->whereIn('state', OrdersSales::ACTIVOS);
                    if ($desde) {
                        /*
                         * De otros días solo entran los PROGRAMADOS para este
                         * periodo: uno hecho el lunes para el miércoles sale
                         * el miércoles, y uno hecho hoy para mañana no sale
                         * hoy. Lo demás, por el día en que se pidió.
                         */
                        $a->where(fn ($r) => $r
                            ->where(fn ($s) => $s->where('is_scheduled', false)
                                ->where('sale_date', '>=', $desde)
                                ->where('sale_date', '<', $hasta))
                            ->orWhere(fn ($s) => $s->where('is_scheduled', true)
                                ->where('delivery_date', '>=', $desde)
                                ->where('delivery_date', '<', $hasta)));
                    }
                })
                    ->orWhere(fn ($q2) => $q2->where('state', OrdersSales::ENTREGADO)
                        ->where($this->cerradoDesde('delivery_date', $desde, $hasta)))
                    ->orWhere(fn ($q2) => $q2->where('state', OrdersSales::CANCELADO)
                        ->where($this->cerradoDesde('updated_at', $desde, $hasta)));
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

        /*
         * UNA VENTA ES LA DE `VentasDelNegocio`: el pedido de la app
         * ENTREGADO y con el pago confirmado, por lo que se cobró de
         * productos (sin el domicilio, que es del domiciliario), más la venta
         * del MOSTRADOR cobrada. Es la misma cifra que la cabecera de
         * Pedidos, Cuánto te queda y el Resumen económico.
         *
         * Antes contaba también lo pagado en línea sin entregar y sumaba el
         * total con el domicilio: «hoy» decía una cifra y Pedidos otra. Un
         * cancelado tampoco cuenta, aunque se hubiera pagado.
         */
        $incomeOrders = fn () => OrdersSales::where('busines_id', $business_id)
            ->confirmados()
            ->where('state', OrdersSales::ENTREGADO);
        $importe = DB::raw(VentasDelNegocio::IMPORTE_APP);
        $mostrador = fn ($desde, $hasta) => VentasDelNegocio::delMostrador((int) $business_id, Carbon::parse($desde), Carbon::parse($hasta));
        // Lo vendido entre dos momentos: la app y el mostrador.
        $vendido = fn ($desde, $hasta) => (float) $incomeOrders()->whereBetween('sale_date', [$desde, $hasta])->sum($importe)
            + (float) $mostrador($desde, $hasta)->sum('total');
        // Cuántas ventas: los pedidos entregados y las del mostrador.
        $cuantas = fn ($desde, $hasta) => $incomeOrders()->whereBetween('sale_date', [$desde, $hasta])->count()
            + $mostrador($desde, $hasta)->count();

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
        $incomeByDay = function ($start, $end) use ($incomeOrders, $mostrador, $daysOfWeek, $diaDeLaSemana) {
            $rows = $incomeOrders()
                ->select(
                    DB::raw("$diaDeLaSemana as weekday"),
                    DB::raw('SUM('.VentasDelNegocio::IMPORTE_APP.') as total_income')
                )
                ->whereBetween('sale_date', [$start, $end])
                ->groupBy('weekday')
                ->get()
                ->keyBy('weekday');

            // Las del mostrador, por el mismo día de la semana (1 = domingo).
            $delMostrador = $mostrador($start, $end)
                ->get(['vendida_at', 'total'])
                ->groupBy(fn ($v) => Carbon::parse($v->vendida_at)->dayOfWeek + 1)
                ->map(fn ($g) => (float) $g->sum('total'));

            $out = [];
            foreach ($daysOfWeek as $key => $day) {
                $out[$day] = (float) ($rows[$key]->total_income ?? 0) + (float) ($delMostrador[$key] ?? 0);
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

        /*
         * Y SE DESGLOSA PARA CADA PERIODO QUE LA PANTALLA SABE ENSEÑAR.
         *
         * Era una sola consulta, la de la semana, y la pantalla de Ganancias
         * la ponía debajo de los tres botones --Hoy, Semana, Mes-- cambiando
         * el pie para que dijera cuál estaba mirando. El pie cambiaba; las
         * cifras no.
         *
         * Se veía en una captura cualquiera: «Desde el primero del mes:
         * $1.149.900» arriba y «Efectivo $0 · En la app $0 · Fiado $0»
         * justo debajo, con el pie diciendo «Mes». Un millón largo que no
         * salió de ningún bolsillo. En una pantalla de dinero una etiqueta
         * que no cuadra con su cifra hace dudar de las dos, y la de arriba
         * era la buena.
         *
         * Son tres `GROUP BY` sobre una tabla que ya se está recorriendo seis
         * veces en esta misma respuesta, así que el coste es el que ya había.
         */
        /*
         * El mostrador entra en los mismos bolsillos: lo cobrado en efectivo
         * está en el cajón como el efectivo de la app; el QR y la
         * transferencia del mostrador van aparte, porque no los liquida la
         * app como un pedido (el QR lo paga la pasarela; la transferencia
         * llega directo).
         */
        $bolsilloDelMostrador = fn (string $forma) => $forma === 'efectivo' ? 'Efectivo' : 'QR o transferencia en el mostrador';
        $porMedioEntre = fn ($desde, $hasta) => $incomeOrders()
            ->whereBetween('sale_date', [$desde, $hasta])
            ->select('methods_id', DB::raw('SUM('.VentasDelNegocio::IMPORTE_APP.') as total'))
            ->groupBy('methods_id')
            ->get()
            ->map(fn ($f) => ['medio' => $bolsillo((int) $f->methods_id), 'total' => (float) $f->total])
            ->concat($mostrador($desde, $hasta)
                ->select('forma_de_pago', DB::raw('SUM(total) as total'))
                ->groupBy('forma_de_pago')
                ->get()
                ->map(fn ($f) => ['medio' => $bolsilloDelMostrador((string) $f->forma_de_pago), 'total' => (float) $f->total]))
            ->groupBy('medio')
            ->map(fn ($g) => (float) $g->sum('total'))
            ->filter(fn ($total) => $total > 0)
            ->sortDesc()
            ->map(fn ($total, $medio) => ['medio' => $medio, 'total' => $total])
            ->values()
            ->all();

        $porMedio = $porMedioEntre($currentWeekStart, $currentWeekEnd);

        $month_start = now()->startOfMonth();
        $month_end = (clone $month_start)->endOfMonth();

        $monthlyIncome = $vendido($month_start, $month_end);

        $totalIncome = (float) $incomeOrders()->sum($importe)
            + (float) DB::table('ventas_mostrador')
                ->where('busines_id', $business_id)
                ->where('estado_pago', 'pagada')
                ->sum('total');

        /* Lo que la pantalla del panel necesita además de los totales: cuántas
           ventas fueron, el ticket promedio, lo de hoy y la tendencia. */
        $cuantasSemana = $cuantas($currentWeekStart, $currentWeekEnd);
        $inicioDeHoy = now()->startOfDay();
        $finDeHoy = now()->endOfDay();

        $ultimasSemanas = collect(range(7, 0))->map(function ($atras) use ($vendido, $currentWeekStart) {
            $inicio = (clone $currentWeekStart)->subWeeks($atras);
            $fin = (clone $inicio)->endOfWeek();

            return [
                'inicio' => $inicio->toDateString(),
                'total' => $vendido($inicio, $fin),
            ];
        })->values()->all();

        /*
         * ────────────────────────────────────────────────────────────────
         * EL RANGO QUE ELIGE EL TENDERO, cuando lo elige.
         *
         * Dia, semana y mes son los de siempre y los decide el servidor.
         * Esto responde a una pregunta concreta --«¿cuanto hice del 3 al
         * 9?»-- y por eso solo viaja si se pide: sin fechas, `rango` es
         * `null` y la respuesta es exactamente la de antes.
         *
         * SE REUSAN LAS MISMAS PIEZAS que los otros periodos --`incomeOrders`
         * y `bolsillo`-- para que la cifra de un rango de siete dias que
         * caiga justo en la semana sea LA MISMA que la de «Semana». Con dos
         * consultas distintas acabarian discrepando en los bordes, y nadie
         * sabria cual creer.
         *
         * CON EL MISMO TOPE DE 92 DIAS que el historial, y por lo mismo: sin
         * el, una fecha de hace cinco anios hace recorrer la tabla entera.
         * ────────────────────────────────────────────────────────────────
         */
        $rango = null;

        if ($request->filled('desde') && $request->filled('hasta')) {
            $desde = Carbon::parse($request->desde)->startOfDay();
            $hasta = Carbon::parse($request->hasta)->endOfDay();

            // Al reves se corrige: es un error de dedo, no una consulta vacia.
            if ($desde->gt($hasta)) {
                [$desde, $hasta] = [$hasta->copy()->startOfDay(), $desde->copy()->endOfDay()];
            }

            if ($desde->diffInDays($hasta) > 92) {
                $desde = $hasta->copy()->subDays(92)->startOfDay();
            }

            $delRango = VentasDelNegocio::entre((int) $business_id, $desde, $hasta);

            $rango = [
                'desde' => $desde->toDateString(),
                'hasta' => $hasta->toDateString(),
                'total' => $delRango['ventas'],
                'ventas' => $delRango['cuantas'],
                'ticket_promedio' => $delRango['ticket_promedio'],
                'por_medio' => $porMedioEntre($desde, $hasta),
                /*
                 * DIA A DIA, pero solo si el rango cabe en una pantalla.
                 *
                 * Con treinta y un dias salen treinta y una barras de ocho
                 * pixeles: una mancha. Pasado el mes se manda vacio y la
                 * pantalla ensena las cifras sin grafica, que es lo honesto.
                 */
                'dia_a_dia' => $desde->diffInDays($hasta) <= 31
                    ? collect(range(0, (int) $desde->diffInDays($hasta)))
                        ->map(function ($i) use ($desde, $vendido) {
                            $dia = $desde->copy()->addDays($i);

                            return [
                                'dia' => $dia->toDateString(),
                                'total' => $vendido($dia->copy()->startOfDay(), $dia->copy()->endOfDay()),
                            ];
                        })
                        ->all()
                    : [],
            ];
        }

        return response()->json([
            'business_id' => $business_id,
            'rango' => $rango,

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
            'previous_week_to_date' => $vendido($previousWeekStart, now()->subWeek()),

            'ventas_semana' => $cuantasSemana,
            'ticket_promedio' => $cuantasSemana ? round((float) $currentWeekTotal / $cuantasSemana, 2) : 0.0,
            // Lo de hoy, la app y el mostrador juntos (`VentasDelNegocio`).
            'hoy' => $vendido($inicioDeHoy, $finDeHoy),
            'ventas_hoy' => $cuantas($inicioDeHoy, $finDeHoy),
            // De eso, lo que salió del mostrador: ya está DENTRO de `hoy`.
            'hoy_mostrador' => (float) $mostrador($inicioDeHoy, $finDeHoy)->sum('total'),
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
            // Y los otros dos periodos de la pantalla de Ganancias, para que
            // el desglose diga lo mismo que la cifra que tiene encima.
            'por_medio_hoy' => $porMedioEntre(now()->startOfDay(), now()->endOfDay()),
            'por_medio_mes' => $porMedioEntre($month_start, $month_end),
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
     * Cerrado desde `$desde` (o desde hoy, si no se dice) hasta `$hasta`,
     * mirando esa columna y cayendo a `sale_date` si está vacía.
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
    private function cerradoDesde(string $columna, $desde, $hasta = null): callable
    {
        $desde = $desde ?? now()->startOfDay();
        $hasta = $hasta ?? now()->addDay()->startOfDay();

        return function ($q) use ($columna, $desde, $hasta) {
            $q->where(fn ($r) => $r->where($columna, '>=', $desde)
                ->where($columna, '<', $hasta))
                ->orWhere(fn ($r) => $r->whereNull($columna)
                    ->where('sale_date', '>=', $desde)
                    ->where('sale_date', '<', $hasta));
        };
    }
}
