<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Códigos de descuento.
 *
 * El código se guarda SIEMPRE en mayúsculas y con índice único: el usuario lo
 * escribe a mano desde el teclado del teléfono y "verano25" y "VERANO25" tienen
 * que ser el mismo cupón. La normalización se hace al guardar, no al comparar,
 * para que el único índice sirva de verdad.
 *
 * `uses_count` se lleva acá aunque `coupon_redemptions` tenga el detalle: el
 * límite hay que comprobarlo en el momento de aplicar el cupón, y contar filas
 * de la tabla de canjes en cada intento es exactamente la consulta que se
 * degrada cuando la promoción funciona.
 *
 * `min_order` frena el caso que arruina la promoción: un fijo de 5.000 sobre un
 * pedido de 6.000 regala el margen entero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();

            $table->string('code', 40)->unique();
            $table->string('description', 255)->nullable();

            // percent: `value` es 0..100 | fixed: `value` es COP
            $table->enum('type', ['percent', 'fixed'])->default('percent');
            $table->decimal('value', 12, 2);

            /*
             * Techo del descuento en los porcentuales. Un 30% sin tope sobre un
             * mercado grande se lleva más de lo que se quiso ofrecer, y es el
             * error que solo se descubre leyendo la factura del mes.
             */
            $table->decimal('max_discount', 12, 2)->nullable();
            $table->decimal('min_order', 12, 2)->default(0);

            // Nulo = sin límite.
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('max_uses_per_user')->nullable();
            $table->unsignedInteger('uses_count')->default(0);

            // Alcance opcional: si van nulos el cupón sirve en toda la
            // plataforma. `category_id` es INT UNSIGNED como su tabla.
            $table->unsignedInteger('business_id')->nullable();
            $table->unsignedInteger('category_id')->nullable();

            // Quién lo financia. Un cupón puede ser cortesía de la plataforma o
            // parte de la pauta que ya paga un anunciante, y sin esto no hay
            // forma de saber a quién descontarle el costo.
            $table->unsignedBigInteger('advertiser_id')->nullable();

            $table->date('starts_at');
            $table->date('ends_at');

            $table->tinyInteger('state')->default(1); // 1 activo | 0 inactivo

            $table->timestamps();

            $table->index(['state', 'starts_at', 'ends_at'], 'cupon_vigencia_idx');

            $table->foreign('business_id')
                ->references('busines_id')->on('business')
                ->cascadeOnDelete();

            $table->foreign('category_id')
                ->references('category_id')->on('category')
                ->cascadeOnDelete();

            $table->foreign('advertiser_id')
                ->references('id')->on('advertisers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
