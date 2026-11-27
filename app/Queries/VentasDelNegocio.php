<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Order\OrdersSales;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * QUÉ ES UNA VENTA DE LA TIENDA. UNA SOLA DEFINICIÓN PARA TODO EL PANEL.
 *
 * Antes cada pantalla tenía la suya: «Ventas hoy» sumaba pedidos aceptados y
 * en camino, Ingresos sumaba el total con el domicilio y lo pagado en línea
 * aunque no se hubiera entregado, Cuánto te queda contaba por día de entrega y
 * con el mostrador, el tablero económico sin el mostrador… y el tendero veía
 * cuatro cifras distintas para «lo que vendí hoy».
 *
 * UNA VENTA es:
 *
 *   - un PEDIDO DE LA APP ENTREGADO (estado 4), con el pago confirmado (sin
 *     los que esperan un pago en línea o se lo rechazaron), del día en que se
 *     pidió (`sale_date`, la fecha con la que se liquida). Vale lo que se
 *     cobró por los productos menos las promociones (`subtotal − discount`):
 *     el domicilio no es de la tienda, es del domiciliario;
 *
 *   - una VENTA DEL MOSTRADOR COBRADA (`estado_pago = pagada`; un QR que
 *     espera o que se canceló no es venta), del día en que se hizo
 *     (`vendida_at`). Vale su total.
 *
 * Lo mismo cuenta en Pedidos (la cabecera), Ingresos, Cuánto te queda, el
 * Resumen económico y el Historial: la app y el mostrador, entregadas y
 * cobradas.
 */
final class VentasDelNegocio
{
    /** El importe de un pedido de la app que es de la tienda. */
    public const IMPORTE_APP = 'subtotal - discount';

    /** @return EloquentBuilder<OrdersSales> */
    public static function pedidosDeLaApp(int $negocio, CarbonInterface $desde, CarbonInterface $hasta): EloquentBuilder
    {
        return OrdersSales::query()
            ->where('busines_id', $negocio)
            ->confirmados()
            ->where('state', OrdersSales::ENTREGADO)
            ->whereBetween('sale_date', [$desde, $hasta]);
    }

    public static function delMostrador(int $negocio, CarbonInterface $desde, CarbonInterface $hasta): Builder
    {
        return DB::table('ventas_mostrador')
            ->where('busines_id', $negocio)
            ->where('estado_pago', 'pagada')
            ->whereBetween('vendida_at', [$desde, $hasta]);
    }

    /**
     * Las ventas de un periodo: cuánto, cuántas y de dónde.
     *
     * @return array{ventas: float, ventas_app: float, ventas_mostrador: float, cuantas: int, cuantas_app: int, cuantas_mostrador: int, ticket_promedio: float}
     */
    public static function entre(int $negocio, CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $app = self::pedidosDeLaApp($negocio, $desde, $hasta)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM('.self::IMPORTE_APP.'), 0) as importe')
            ->toBase()
            ->first();

        $mostrador = self::delMostrador($negocio, $desde, $hasta)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as importe')
            ->first();

        $ventasApp = round((float) ($app->importe ?? 0), 2);
        $ventasMostrador = round((float) ($mostrador->importe ?? 0), 2);
        $cuantasApp = (int) ($app->n ?? 0);
        $cuantasMostrador = (int) ($mostrador->n ?? 0);
        $cuantas = $cuantasApp + $cuantasMostrador;
        $ventas = round($ventasApp + $ventasMostrador, 2);

        return [
            'ventas' => $ventas,
            'ventas_app' => $ventasApp,
            'ventas_mostrador' => $ventasMostrador,
            'cuantas' => $cuantas,
            'cuantas_app' => $cuantasApp,
            'cuantas_mostrador' => $cuantasMostrador,
            // «Valor promedio por venta»: sobre las dos, no solo la app.
            'ticket_promedio' => $cuantas ? round($ventas / $cuantas, 2) : 0.0,
        ];
    }

    /** Los estados de un pedido EN CURSO: aceptado, en camino o volviendo. */
    public const EN_CURSO = [OrdersSales::PREPARANDO, OrdersSales::EN_CAMINO, OrdersSales::DEVOLVIENDO];

    /**
     * Cuántos pedidos están en curso, con la MISMA regla que el tablero de
     * Pedidos para el día: los pedidos hoy y los programados para hoy. Los
     * que siguen abiertos de días anteriores van aparte (`de_antes`): no
     * salen en el tablero de hoy, y contarlos en la cabecera hacía que la
     * cifra no cuadrara con las columnas (9 arriba, 5 abajo).
     *
     * @return array{hoy: int, de_antes: int}
     */
    public static function enCurso(int $negocio, CarbonInterface $dia): array
    {
        $desde = $dia->copy()->startOfDay();
        $hasta = $dia->copy()->addDay()->startOfDay();

        $base = fn () => OrdersSales::query()
            ->where('busines_id', $negocio)
            ->confirmados()
            ->whereIn('state', self::EN_CURSO);

        $hoy = $base()->where(fn ($r) => $r
            ->where(fn ($s) => $s->where('is_scheduled', false)
                ->where('sale_date', '>=', $desde)->where('sale_date', '<', $hasta))
            ->orWhere(fn ($s) => $s->where('is_scheduled', true)
                ->where('delivery_date', '>=', $desde)->where('delivery_date', '<', $hasta)))
            ->count();

        $deAntes = $base()->where(fn ($r) => $r
            ->where(fn ($s) => $s->where('is_scheduled', false)->where('sale_date', '<', $desde))
            ->orWhere(fn ($s) => $s->where('is_scheduled', true)->where('delivery_date', '<', $desde)))
            ->count();

        return ['hoy' => $hoy, 'de_antes' => $deAntes];
    }

    /**
     * Los pedidos de hoy: los de la app que entraron hoy, sin los cancelados,
     * y las ventas cobradas en el mostrador.
     *
     * @return array{total: int, app: int, mostrador: int}
     */
    public static function pedidosDelDia(int $negocio, CarbonInterface $dia): array
    {
        $desde = $dia->copy()->startOfDay();
        $hasta = $dia->copy()->endOfDay();

        $app = OrdersSales::query()
            ->where('busines_id', $negocio)
            ->confirmados()
            ->where('state', '!=', OrdersSales::CANCELADO)
            ->whereBetween('sale_date', [$desde, $hasta])
            ->count();
        $mostrador = self::delMostrador($negocio, $desde, $hasta)->count();

        return ['total' => $app + $mostrador, 'app' => $app, 'mostrador' => $mostrador];
    }
}
