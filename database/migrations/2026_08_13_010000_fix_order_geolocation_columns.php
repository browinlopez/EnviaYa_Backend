<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La tabla order_geolocation quedó desalineada con el modelo y con el
 * controlador desde su creación, así que el seguimiento nunca llegó a
 * funcionar: cada POST a /orders/geolocation devolvía 500.
 *
 *   - Faltaba `orderSales_id`, aunque el modelo lo declara fillable, la
 *     validación lo exige y hay una relación order() que lo usa.
 *   - La columna de longitud se llamó `length` (traducción literal de
 *     "longitud"); el resto del código la busca como `longitude`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_geolocation', function (Blueprint $table) {
            if (Schema::hasColumn('order_geolocation', 'length')
                && !Schema::hasColumn('order_geolocation', 'longitude')) {
                $table->renameColumn('length', 'longitude');
            }
        });

        Schema::table('order_geolocation', function (Blueprint $table) {
            if (!Schema::hasColumn('order_geolocation', 'orderSales_id')) {
                $table->unsignedInteger('orderSales_id')
                    ->nullable()
                    ->after('domiciliary_id');

                $table->index('orderSales_id', 'fk_order_geolocation_order');

                $table->foreign('orderSales_id', 'fk_order_geolocation_order')
                    ->references('orderSales_id')->on('orderssales')
                    ->onDelete('cascade');
            }
        });

        // Consulta caliente del seguimiento: la última posición de un pedido.
        Schema::table('order_geolocation', function (Blueprint $table) {
            $table->index(['orderSales_id', 'created_at'], 'idx_geolocation_order_fecha');
        });
    }

    public function down(): void
    {
        Schema::table('order_geolocation', function (Blueprint $table) {
            $table->dropIndex('idx_geolocation_order_fecha');
            $table->dropForeign('fk_order_geolocation_order');
            $table->dropIndex('fk_order_geolocation_order');
            $table->dropColumn('orderSales_id');
        });

        Schema::table('order_geolocation', function (Blueprint $table) {
            $table->renameColumn('longitude', 'length');
        });
    }
};
