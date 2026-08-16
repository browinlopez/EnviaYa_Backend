<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El descuento aplicado y con qué cupón, en la propia orden.
 *
 * Sin estas dos columnas el cupón sería decorativo: `total` bajaría y no
 * cuadraría contra `subtotal + domicilio`, y nadie podría explicar la
 * diferencia mirando el pedido. La tabla `coupon_redemptions` guarda el canje
 * desde el lado del cupón; esto lo guarda desde el lado de la venta, que es
 * donde se mira cuando un negocio pregunta por qué le entró menos.
 *
 * `discount` va con default 0 y no nulo: la inmensa mayoría de pedidos no
 * lleva cupón, y un cero es más fácil de sumar que un NULL en cada reporte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->decimal('discount', 10, 2)->default(0)->after('domicilio');
            $table->unsignedBigInteger('coupon_id')->nullable()->after('discount');

            $table->foreign('coupon_id')
                ->references('id')->on('coupons')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->dropForeign(['coupon_id']);
            $table->dropColumn(['discount', 'coupon_id']);
        });
    }
};
