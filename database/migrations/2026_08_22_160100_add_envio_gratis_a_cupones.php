<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un tercer tipo de cupón: envío gratis.
 *
 * Los dos que había —`percent` y `fixed`— solo saben rebajar el subtotal de
 * productos. Se hizo así a propósito y el motivo sigue siendo válido: rebajar
 * el domicilio salía del bolsillo del domiciliario. Lo que cambia ahora es que
 * la plataforma puede subsidiarlo (`orderssales.delivery_subsidy`), y entonces
 * el tipo nuevo sí tiene sentido: el cliente no paga el domicilio, el
 * repartidor cobra igual, y el costo queda anotado donde se puede medir.
 *
 * `value`, `max_discount` y `min_order` no aplican a este tipo: la rebaja es
 * siempre la tarifa completa. Quien lo interpreta es `PoliticaDeDomicilio`, no
 * `Coupon::descuentoPara()`, que sigue siendo solo del subtotal.
 *
 * Se usa `change()` y no un ALTER a mano porque el enum no es solo cosa de
 * MySQL: en SQLite —el motor de las pruebas— Laravel lo impone con una
 * restricción CHECK, y ahí un INSERT con el valor nuevo falla igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->enum('type', ['percent', 'fixed', 'free_shipping'])
                ->default('percent')
                ->change();
        });
    }

    public function down(): void
    {
        // Los cupones de envío gratis pasan a porcentaje de 0: se quedan sin
        // efecto, pero no se pierden ni rompen la restricción al reducirla.
        DB::table('coupons')->where('type', 'free_shipping')
            ->update(['type' => 'percent', 'value' => 0]);

        Schema::table('coupons', function (Blueprint $table) {
            $table->enum('type', ['percent', 'fixed'])->default('percent')->change();
        });
    }
};
