<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EL EFECTIVO QUE EL DOMICILIARIO LE ENTREGA A LA TIENDA EN EL MOSTRADOR.
 *
 * Hasta ahora el domiciliario solo podía saldar su efectivo consignándole a
 * VeciPa'Ya. En una tienda de barrio lo normal es otra cosa: vuelve y le
 * entrega al tendero lo que cobró. Esa entrega es una consignación más —baja
 * lo que el domiciliario tiene encima— pero hecha a la TIENDA, así que:
 *
 *  · `canal` dice a quién se le entregó: 'consignacion' (a la plataforma,
 *    como siempre) o 'mostrador' (a la tienda).
 *  · `busines_id` dice a qué tienda, en las de mostrador.
 *  · `settlement_id` ata la entrega al corte de la tienda que la descontó. La
 *    tienda ya tiene esa plata en la mano: si el corte le pagara además el
 *    subtotal de esos pedidos, cobraría dos veces.
 *  · `settlements.counter_cash` es cuánto se descontó por eso en cada corte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_deposits', function (Blueprint $table) {
            $table->string('canal', 20)->default('consignacion')->after('domiciliary_id');
            $table->unsignedInteger('busines_id')->nullable()->after('canal');
            $table->unsignedBigInteger('settlement_id')->nullable()->after('busines_id');
            $table->index(['busines_id', 'canal', 'state'], 'cash_deposits_tienda_idx');
        });

        Schema::table('settlements', function (Blueprint $table) {
            $table->decimal('counter_cash', 12, 2)->default(0)->after('carried_from_id');
        });
    }

    public function down(): void
    {
        Schema::table('cash_deposits', function (Blueprint $table) {
            $table->dropIndex('cash_deposits_tienda_idx');
            $table->dropColumn(['canal', 'busines_id', 'settlement_id']);
        });

        Schema::table('settlements', function (Blueprint $table) {
            $table->dropColumn('counter_cash');
        });
    }
};
