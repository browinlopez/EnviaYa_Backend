<?php

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Models\Order\OrdersSales;
use App\Services\CreditoDeTienda;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * EL ARCHIVO DE PEDIDOS DE LA TIENDA.
 *
 * El tablero es lo de AHORA: lo que hay que aceptar, preparar y despachar,
 * más lo que se cerró hoy. Todo lo anterior vivía en la misma respuesta —el
 * endpoint del tablero devolvía cada pedido desde que la tienda abrió— y eso
 * tiene dos problemas: la respuesta crece sin tope, y la columna «Entregados»
 * acababa enseñando entregas de hace meses.
 *
 * Acá vive el resto, con su rango de fechas. Es la pantalla a la que alguien
 * entra con una pregunta concreta: «¿y el pedido de la señora del martes?»,
 * «¿cuánto vendí la semana pasada?», «¿cuántos me cancelaron?».
 *
 * SOLO PEDIDOS CERRADOS. Los abiertos no son historia: están en el tablero y
 * sacarlos de ahí sería esconderlos. Si alguien pide el rango de hoy, lo que
 * ve son los cerrados de hoy, los mismos de la cuarta columna.
 *
 * EL RANGO SE ACOTA A 92 DÍAS. Sin tope, una fecha de hace cinco años hace
 * que el servidor recorra la tabla entera para pintar una pantalla que nadie
 * va a leer entera. Tres meses cubren «la semana pasada», «el mes pasado» y
 * el trimestre, que es lo que de verdad se consulta.
 */
class HistorialDePedidosController extends Controller
{
    /** Estados que cuentan como cerrados. */
    private const ENTREGADO = 4;
    private const CANCELADO = 5;

    private const DIAS_MAXIMOS = 92;

    public function index(Request $request)
    {
        $businessId = (int) $request->attributes->get('busines_id');

        $datos = $request->validate([
            'desde'  => 'nullable|date',
            'hasta'  => 'nullable|date',
            'estado' => 'nullable|in:todos,entregados,cancelados',
            'pago'   => 'nullable|in:todos,efectivo,app,fiado',
            'pagina' => 'nullable|integer|min:1',
        ]);

        [$desde, $hasta] = $this->ventana($datos);

        $base = fn () => OrdersSales::where('busines_id', $businessId)
            ->confirmados()
            ->whereIn('state', [self::ENTREGADO, self::CANCELADO])
            ->whereBetween('sale_date', [$desde, $hasta]);

        $consulta = $base();

        if (($datos['estado'] ?? 'todos') === 'entregados') {
            $consulta->where('state', self::ENTREGADO);
        } elseif (($datos['estado'] ?? 'todos') === 'cancelados') {
            $consulta->where('state', self::CANCELADO);
        }

        /*
         * Tres bolsillos, no los siete métodos de la tabla: efectivo, el
         * crédito de la tienda y todo lo demás —tarjetas, QR,
         * transferencias—, que para el tendero es la misma plata. Es el mismo
         * criterio que usa la pantalla de Ingresos.
         */
        $pago = $datos['pago'] ?? 'todos';

        if ($pago === 'efectivo') {
            $consulta->where('methods_id', 1);
        } elseif ($pago === 'fiado') {
            $consulta->where('methods_id', CreditoDeTienda::METODO);
        } elseif ($pago === 'app') {
            $consulta->whereNotIn('methods_id', [1, CreditoDeTienda::METODO]);
        }

        $pedidos = $consulta
            ->with(['buyer.user:user_id,name', 'domiciliary.user:user_id,name'])
            ->orderByDesc('sale_date')
            ->paginate(25, ['*'], 'pagina', $datos['pagina'] ?? 1)
            /*
             * Sólo lo que la tabla enseña. Con los modelos completos salían el
             * correo del comprador y del domiciliario, su rol y su estado, y
             * columnas internas del pedido (comisiones, la ruta trazada…).
             * La forma anidada —`buyer.user.name`— se conserva para que la
             * pantalla no cambie.
             */
            ->through(fn ($p) => [
                'orderSales_id' => $p->orderSales_id,
                'sale_date'     => $p->sale_date,
                'delivery_date' => $p->delivery_date,
                'total'         => (float) $p->total,
                'methods_id'    => (int) $p->methods_id,
                'state'         => (int) $p->state,
                'buyer'         => ['user' => ['name' => $p->buyer?->user?->name]],
                'domiciliary'   => ['user' => ['name' => $p->domiciliary?->user?->name]],
            ]);

        /*
         * LOS TOTALES SON DEL PERIODO, NO DE LO FILTRADO.
         *
         * Dos razones. La primera: un resumen que cambiara al pasar de página
         * no resume nada. La segunda, y es la que manda: «Cancelados 2» solo
         * significa algo al lado de «Pedidos 46». Si el filtro de pago o de
         * estado moviera las cifras, las cuatro dejarían de ser el resumen de
         * un periodo y pasarían a ser cuatro respuestas a preguntas distintas
         * con la misma pinta.
         *
         * Los filtros acotan la TABLA, que es donde se busca un pedido
         * concreto.
         */
        $entregados = (clone $base())->where('state', self::ENTREGADO);

        return response()->json([
            'periodo' => [
                'desde' => $desde->toDateString(),
                'hasta' => $hasta->toDateString(),
            ],
            'totales' => [
                'pedidos'    => (int) (clone $base())->count(),
                'entregados' => (int) (clone $entregados)->count(),
                'cancelados' => (int) (clone $base())->where('state', self::CANCELADO)->count(),
                // Sin el domicilio: ese dinero es del domiciliario, no de la
                // tienda. Es la misma regla que la cifra «Ventas hoy».
                'vendido'    => (float) (clone $entregados)->sum(DB::raw('subtotal - discount')),
                /*
                 * CUÁNTOS LLEGARON A TIEMPO, y sobre cuántos se puede decir.
                 *
                 * Solo cuentan los que tienen las dos marcas —cuándo salió y
                 * cuándo se entregó— y un plazo prometido congelado. Los
                 * viejos no las tienen, y un «100 % a tiempo» calculado sobre
                 * tres pedidos de cuarenta sería una cifra inventada. Por eso
                 * viaja también `medidos`: la pantalla solo enseña el
                 * porcentaje cuando hay sobre qué.
                 */
                'a_tiempo'   => $this->aTiempo((clone $entregados)),
            ],
            'pedidos' => $pedidos,
        ]);
    }

    /**
     * Cuántos de los entregados cumplieron el plazo prometido.
     *
     * Devuelve `null` en `porcentaje` cuando no hay ninguno medible: decir
     * «0 %» sobre cero pedidos con marcas es afirmar algo que no se sabe.
     */
    private function aTiempo($entregados): array
    {
        $medibles = (clone $entregados)
            ->whereNotNull('dispatched_at')
            ->whereNotNull('delivery_date')
            ->whereNotNull('promised_minutes')
            ->get(['dispatched_at', 'delivery_date', 'promised_minutes']);

        if ($medibles->isEmpty()) {
            return ['medidos' => 0, 'a_tiempo' => 0, 'porcentaje' => null];
        }

        $aTiempo = $medibles->filter(function ($o) {
            $minutos = Carbon::parse($o->dispatched_at)
                ->diffInMinutes(Carbon::parse($o->delivery_date), false);

            return $minutos >= 0 && $minutos <= (int) $o->promised_minutes;
        })->count();

        return [
            'medidos'    => $medibles->count(),
            'a_tiempo'   => $aTiempo,
            'porcentaje' => (int) round(($aTiempo / $medibles->count()) * 100),
        ];
    }

    /**
     * El rango pedido, acotado.
     *
     * Por defecto, la semana en curso: es la pregunta que más se hace y la
     * que el panel puede contestar sin que nadie escriba una fecha.
     */
    private function ventana(array $datos): array
    {
        $hasta = isset($datos['hasta'])
            ? Carbon::parse($datos['hasta'])->endOfDay()
            : now()->endOfDay();

        $desde = isset($datos['desde'])
            ? Carbon::parse($datos['desde'])->startOfDay()
            : now()->startOfWeek()->startOfDay();

        // Al revés se corrige en vez de devolver vacío: es un error de dedo,
        // no una consulta sin resultados.
        if ($desde->gt($hasta)) {
            [$desde, $hasta] = [$hasta->copy()->startOfDay(), $desde->copy()->endOfDay()];
        }

        if ($desde->diffInDays($hasta) > self::DIAS_MAXIMOS) {
            $desde = $hasta->copy()->subDays(self::DIAS_MAXIMOS)->startOfDay();
        }

        return [$desde, $hasta];
    }
}
