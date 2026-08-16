<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La orden solo guardaba `total`, así que el desglose se perdía en cuanto se
 * creaba: nadie podía saber cuánto de ese total era producto y cuánto era
 * domicilio. Eso obligaba a recalcularlo a mano en cada sitio (y en
 * updateStatus estaba quemado en 2000), y dejaba al domiciliario sin saber
 * cuánto le corresponde hasta que existiera un registro de pago.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->decimal('subtotal', 10, 2)->default(0)->after('total');
            $table->decimal('domicilio', 10, 2)->default(0)->after('subtotal');
        });

        /*
         * Relleno de las órdenes existentes.
         *
         * El subtotal se reconstruye desde el detalle, que sí quedó guardado.
         * El domicilio es la diferencia contra el total: en las órdenes viejas
         * da 0 porque la tarifa nunca se le sumó al cliente, y ese 0 es el
         * dato correcto — no se les puede inventar un cobro que no ocurrió.
         */
        DB::statement("
            UPDATE orderssales o
            LEFT JOIN (
                SELECT orderSales_id, SUM(amount * unit_price) AS suma
                FROM orderssales_detail
                GROUP BY orderSales_id
            ) d ON d.orderSales_id = o.orderSales_id
            SET o.subtotal  = COALESCE(d.suma, o.total),
                o.domicilio = GREATEST(o.total - COALESCE(d.suma, o.total), 0)
        ");
    }

    public function down(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->dropColumn(['subtotal', 'domicilio']);
        });
    }
};
