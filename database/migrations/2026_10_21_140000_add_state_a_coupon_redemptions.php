<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EL CUPÓN DE UN PEDIDO CANCELADO NO VUELVE AL CUPO, Y DEBERÍA.
 *
 * Al cancelar se devuelve el inventario, el cupo de fiado y —desde que existe
 * la redención— los usos de las promociones. El cupón, que está en la misma
 * lista, no: `CouponService` tiene `resolver()` y `canjear()` y nunca tuvo
 * `devolver()`. Un cupón de un solo uso se gasta en un pedido que acabó no
 * existiendo, y quien lo tenía se queda sin él.
 *
 * SE MARCA, NO SE BORRA, igual que en `orders_promotions`. Saber que ese
 * pedido llegó a llevar ese cupón sigue valiendo para entender por qué se
 * cobró lo que se cobró, y marcar en vez de borrar hace que cancelar dos
 * veces no descuente dos veces: la segunda no encuentra canjes aplicados.
 *
 * `1` por defecto —aplicado— es lo correcto para los canjes que ya están: son
 * de pedidos que existen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupon_redemptions', function (Blueprint $table) {
            $table->tinyInteger('state')->default(1)->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('coupon_redemptions', function (Blueprint $table) {
            $table->dropColumn('state');
        });
    }
};
