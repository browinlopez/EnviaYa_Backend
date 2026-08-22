<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pedidos que SÍ se pagaron pero quedaron marcados como pendientes.
 *
 * `payment_state` no estaba en el `$fillable` de OrdersSales, así que Eloquent
 * descartaba el valor en silencio: el webhook de la pasarela marca el pago con
 * `$order->update(['payment_state' => 'paid'])` y eso nunca llegó a escribir
 * nada. El pago quedaba aprobado en su tabla y el pedido decía "pendiente".
 *
 * Mientras un pedido sin pagar se le enseñaba igual a todo el mundo, esto era
 * una incoherencia de tableros. Desde que los pedidos sin pagar se ocultan,
 * pasa a ser grave: son pedidos COBRADOS que desaparecerían de la lista de la
 * tienda y del historial del cliente.
 *
 * Se corrigen solo los que tienen un pago aprobado de verdad en `payments`. Los
 * que no lo tienen se quedan como están: son intentos que fallaron.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('orderssales as o')
            ->join('payments as p', 'p.orderSales_id', '=', 'o.orderSales_id')
            ->whereIn('o.payment_state', ['pending_online', 'rejected'])
            ->where('p.payment_status', 1)
            ->distinct()
            ->pluck('o.orderSales_id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('orderssales')
            ->whereIn('orderSales_id', $ids)
            ->update(['payment_state' => 'paid']);
    }

    public function down(): void
    {
        // No se revierte: devolverlos a "pendiente" volvería a esconder pedidos
        // que están pagados, que es justo el problema que esto arregla.
    }
};
