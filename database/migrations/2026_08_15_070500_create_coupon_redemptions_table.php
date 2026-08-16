<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién usó cada cupón, en qué pedido y cuánto se descontó.
 *
 * El monto se guarda aunque se pueda recalcular: si mañana se corrige el
 * porcentaje del cupón, lo ya canjeado debe seguir contando lo que realmente
 * se descontó ese día. Recalcularlo reescribiría la historia contable.
 *
 * El único (coupon_id, order_id) impide que un reintento del cliente —una
 * conexión que se cae justo al confirmar es lo normal en la calle— aplique dos
 * veces el mismo descuento al mismo pedido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('coupon_id');
            $table->unsignedBigInteger('user_id');

            // `orderssales.orderSales_id` es INT UNSIGNED (increments).
            $table->unsignedInteger('order_id')->nullable();

            $table->decimal('discount', 12, 2);

            $table->timestamps();

            $table->unique(['coupon_id', 'order_id'], 'canje_unico_por_pedido');
            $table->index(['coupon_id', 'user_id'], 'canje_cupon_usuario_idx');

            $table->foreign('coupon_id')
                ->references('id')->on('coupons')
                ->cascadeOnDelete();

            $table->foreign('user_id')
                ->references('user_id')->on('user')
                ->cascadeOnDelete();

            $table->foreign('order_id')
                ->references('orderSales_id')->on('orderssales')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }
};
