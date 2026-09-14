<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SI ESTE PEDIDO YA LE RESTÓ UNIDADES AL INVENTARIO
 *
 * Las existencias empiezan a descontarse al comprar, y hacen falta dos cosas que
 * sin esta marca no se pueden saber:
 *
 *  · UN REINTENTO DE PAGO NO PUEDE RESTAR DOS VECES. `ArmadoDelPedido` reutiliza
 *    el pedido que quedó a medias y reescribe sus líneas; sin marca, cada clic en
 *    «pagar» volvería a restar el carrito entero.
 *
 *  · LOS PEDIDOS DE ANTES NO DESCONTARON NADA. Si al cancelarse devolvieran sus
 *    unidades, el inventario subiría por encima de lo que la tienda tiene. Nacen
 *    en falso, y cancelarlos no toca nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->boolean('stock_descontado')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->dropColumn('stock_descontado');
        });
    }
};
