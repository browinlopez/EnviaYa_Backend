<?php

namespace App\Services;

use App\Models\Order\OrdersSales;
use App\Models\Product\ProductBusiness;
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

    private function mover(OrdersSales $pedido, int $signo): void
    {
        $lineas = DB::table('orderssales_detail')
            ->where('orderSales_id', $pedido->orderSales_id)
            ->select('product_id', DB::raw('SUM(amount) as unidades'))
            ->groupBy('product_id')
            ->get();

        foreach ($lineas as $linea) {
            $consulta = ProductBusiness::where('busines_id', $pedido->busines_id)
                ->where('products_id', $linea->product_id);

            $signo < 0
                ? $consulta->decrement('amount', (int) $linea->unidades)
                : $consulta->increment('amount', (int) $linea->unidades);
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
