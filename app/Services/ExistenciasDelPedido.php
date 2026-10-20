<?php

namespace App\Services;

use App\Models\Order\OrdersSales;
use App\Models\Product\ProductBusiness;
use App\Support\UnidadDeVenta;
use Illuminate\Support\Facades\DB;

/**
 * Las unidades que un pedido le quita al inventario de la tienda.
 *
 * SE RESTA AL COMPRAR Y SE DEVUELVE AL CANCELAR. Antes `products_business.amount`
 * era decorativo: nada lo tocaba al vender, así que un producto nunca se veía
 * agotado aunque en el mostrador ya no quedara.
 *
 * PUEDE QUEDAR EN NEGATIVO, Y ES A PROPÓSITO. El número de la tienda casi nunca
 * es exacto —en producción todo estaba cargado en 10— y bloquear una venta por
 * un inventario que nadie contó es perder el pedido de un producto que sí está en
 * el estante. Un negativo no impide vender: le dice al tendero que su conteo va
 * atrasado. La columna es un entero con signo, así que lo admite.
 *
 * La resta es atómica en la base (`amount = amount - n`): dos pedidos del mismo
 * producto a la vez no se pisan leyendo el mismo valor viejo.
 *
 * Todo pasa por la marca `stock_descontado` del pedido, que es lo que hace a los
 * dos métodos seguros de llamar más de una vez.
 */
class ExistenciasDelPedido
{
    /** Resta las líneas del pedido. No hace nada si ya estaban restadas. */
    public function descontar(OrdersSales $pedido): void
    {
        if ($pedido->stock_descontado) {
            return;
        }

        $this->mover($pedido, -1);
        $this->marcar($pedido, true);
    }

    /** Devuelve lo que el pedido había restado. Nada si no restó nada. */
    public function devolver(OrdersSales $pedido): void
    {
        if (! $pedido->stock_descontado) {
            return;
        }

        $this->mover($pedido, +1);
        $this->marcar($pedido, false);
    }

    /**
     * Mueve el inventario del pedido entero.
     *
     * DOS CAMINOS, y estan separados a proposito. Lo que se vende por unidad
     * se mueve en `amount`, que es entero; lo que se vende al peso, en
     * `stock_weight`, que no lo es.
     *
     * Si fueran la misma consulta, el `(int)` de abajo --que existe porque
     * `amount` es entero-- truncaria el peso: un pedido de 1,8 kg descontaria
     * 1. Con dos caminos, el entero nunca toca una cantidad con decimales.
     */
    private function mover(OrdersSales $pedido, int $signo): void
    {
        $porUnidad = DB::table('orderssales_detail')
            ->where('orderSales_id', $pedido->orderSales_id)
            ->where(function ($q) {
                $q->whereNull('sale_unit')->orWhere('sale_unit', UnidadDeVenta::UNIDAD);
            })
            ->select('product_id', DB::raw('SUM(amount) as unidades'))
            ->groupBy('product_id')
            ->get();

        foreach ($porUnidad as $linea) {
            $consulta = ProductBusiness::where('busines_id', $pedido->busines_id)
                ->where('products_id', $linea->product_id);

            $signo < 0
                ? $consulta->decrement('amount', (int) $linea->unidades)
                : $consulta->increment('amount', (int) $linea->unidades);
        }

        /*
         * Lo pesado manda sobre lo pedido: es lo que de verdad salio de la
         * tienda. Mientras nadie haya pesado vale lo pedido, y al pesar se
         * mueve solo la diferencia --ver `ajustarPorPesaje`--.
         */
        $alPeso = DB::table('orderssales_detail')
            ->where('orderSales_id', $pedido->orderSales_id)
            ->whereNotNull('sale_unit')
            ->where('sale_unit', '!=', UnidadDeVenta::UNIDAD)
            ->select(
                'product_id',
                DB::raw('SUM(COALESCE(qty_final, qty_requested, 0)) as cantidad'),
            )
            ->groupBy('product_id')
            ->get();

        foreach ($alPeso as $linea) {
            $consulta = ProductBusiness::where('busines_id', $pedido->busines_id)
                ->where('products_id', $linea->product_id);

            $signo < 0
                ? $consulta->decrement('stock_weight', (float) $linea->cantidad)
                : $consulta->increment('stock_weight', (float) $linea->cantidad);
        }
    }

    /**
     * AL PESAR, SOLO SE MUEVE LA DIFERENCIA.
     *
     * El inventario ya se descontó al crear el pedido, con lo que el cliente
     * pidió. Cuando el tendero pesa, lo que sale de verdad es otra cosa: si
     * pidió 1 kg y pesó 1,18, faltan 0,18 por descontar.
     *
     * No se hace con `devolver()` + `descontar()`: la marca
     * `stock_descontado` es booleana y no distingue, y devolver soltaría
     * también las unidades enteras del mismo pedido para volver a restarlas,
     * dejando el inventario en el aire entre las dos operaciones.
     *
     * @param  array<int, float>  $deltas  product_id => cuánto más (o menos).
     */
    public function ajustarPorPesaje(OrdersSales $pedido, array $deltas): void
    {
        foreach ($deltas as $productoId => $delta) {
            if (abs($delta) < 0.0005) {
                continue;
            }

            $consulta = ProductBusiness::where('busines_id', $pedido->busines_id)
                ->where('products_id', $productoId);

            $delta > 0
                ? $consulta->decrement('stock_weight', (float) $delta)
                : $consulta->increment('stock_weight', (float) abs($delta));
        }
    }

    /*
     * Por consulta y no con `save()`: la columna no está en `$fillable`, y un
     * guardado del modelo dispararía sus eventos —avisos, retransmisión— por un
     * cambio que no es del pedido sino del inventario.
     */
    private function marcar(OrdersSales $pedido, bool $valor): void
    {
        OrdersSales::whereKey($pedido->getKey())->update(['stock_descontado' => $valor]);
        $pedido->stock_descontado = $valor;
    }
}
