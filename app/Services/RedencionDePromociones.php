<?php

namespace App\Services;

use App\Models\Order\OrdersSales;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DEJAR CONSTANCIA DE QUÉ PROMOCIÓN REBAJÓ QUÉ PEDIDO, Y CONTAR SUS USOS.
 *
 * Esto faltaba entero. La tabla `orders_promotions` existe desde 2025, con su
 * índice único y sus dos relaciones declaradas en los modelos, pero NUNCA se
 * ha escrito en ella: el descuento se calculaba, se aplicaba y se cobraba sin
 * dejar rastro de qué promoción lo había causado. `OrdersSales::toApi()`
 * devolvía `'promotions' => []` siempre, para todos los pedidos de la
 * historia.
 *
 * Por eso poner un tope de usos no es añadir un contador: es construir la
 * redención que faltaba. Y el contador sin la constancia no serviría —no
 * habría forma de saber si un pedido ya gastó su uso.
 *
 * VA SIEMPRE DENTRO DE LA TRANSACCIÓN DEL PEDIDO, igual que el canje del
 * cupón: si el cobro falla después, el uso se libera solo.
 */
class RedencionDePromociones
{
    /** La promoción se aplicó a este pedido. */
    public const APLICADA = 1;

    /** Se aplicó y después el pedido se canceló: el uso volvió al cupo. */
    public const DEVUELTA = 0;

    /**
     * Gastar un uso por cada promoción que rebajó este pedido.
     *
     * `$aplicadas` es `$cifras['promociones']`, que `EconomiaDelPedido` ya trae
     * calculado: no hay que volver a decidir qué promoción entra.
     *
     * @param  array  $aplicadas  [['promotion_id' => int, 'texto' => string, ...], ...]
     */
    public function canjear(OrdersSales $order, array $aplicadas): void
    {
        foreach ($aplicadas as $aplicada) {
            $promocionId = (int) ($aplicada['promotion_id'] ?? 0);

            if ($promocionId <= 0) {
                continue;
            }

            /*
             * LA CONSTANCIA VA PRIMERO, Y ESO ES LO QUE HACE EL CONTADOR
             * IDEMPOTENTE.
             *
             * Un reintento de pago NO crea otro pedido: `ArmadoDelPedido`
             * reutiliza el que quedó a medias y vuelve a pasar por acá. Sin
             * esto, el mismo pedido gastaría un uso cada vez que el cliente le
             * da a pagar.
             *
             * El único de `(orderSales_id, promotion_id)` lo resuelve sin una
             * sola consulta de más: si la fila ya estaba, `insertOrIgnore`
             * devuelve 0 y no se cuenta nada.
             */
            $esNueva = DB::table('orders_promotions')->insertOrIgnore([
                'orderSales_id' => (int) $order->orderSales_id,
                'promotion_id'  => $promocionId,
                'state'         => self::APLICADA,
            ]);

            if (!$esNueva) {
                continue;
            }

            /*
             * El cupo se comprueba ACÁ con un UPDATE condicional en vez de
             * confiar en el filtro que ya hizo `DescuentoPorPromocion`. Entre
             * que se calcula el carrito y se guarda el pedido pasa tiempo —el
             * cliente mira, duda, paga— y con una promoción que funciona hay
             * varias personas gastando el último uso a la vez. Solo la fila
             * que el motor deje pasar cuenta; las demás se enteran de que
             * llegaron tarde.
             *
             * Es el mismo patrón, y por el mismo motivo, que
             * `CouponService::canjear()`.
             */
            $consumido = DB::table('promotions')
                ->where('promotion_id', $promocionId)
                ->whereRaw('(max_usos IS NULL OR usos < max_usos)')
                ->increment('usos');

            if (!$consumido) {
                $nombre = trim((string) ($aplicada['texto'] ?? ''));

                throw new RuntimeException($nombre !== ''
                    ? "La promoción «{$nombre}» se acaba de agotar. Vuelve al carrito para ver el precio sin ella."
                    : 'Una de las promociones de tu pedido se acaba de agotar. Vuelve al carrito para ver el precio sin ella.');
            }
        }
    }

    /**
     * El pedido se canceló: los usos vuelven al cupo.
     *
     * La constancia NO se borra, se marca. Saber que ese pedido llegó a llevar
     * esa promoción sigue valiendo para entender por qué se cobró lo que se
     * cobró, y marcar en vez de borrar hace que cancelar dos veces no
     * descuente dos veces: la segunda no encuentra filas aplicadas.
     *
     * `usos > 0` protege de dejar el contador en negativo si algo se
     * descuadró: un cupo negativo haría la promoción inaplicable para siempre.
     */
    public function devolver(OrdersSales $order): void
    {
        $filas = DB::table('orders_promotions')
            ->where('orderSales_id', (int) $order->orderSales_id)
            ->where('state', self::APLICADA);

        $ids = (clone $filas)->pluck('promotion_id');

        if ($ids->isEmpty()) {
            return;
        }

        $filas->update(['state' => self::DEVUELTA]);

        DB::table('promotions')
            ->whereIn('promotion_id', $ids)
            ->where('usos', '>', 0)
            ->decrement('usos');
    }
}
