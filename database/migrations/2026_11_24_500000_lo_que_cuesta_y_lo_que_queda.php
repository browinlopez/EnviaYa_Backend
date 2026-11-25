<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * «CUÁNTO TE QUEDA»: LO QUE HACE FALTA PARA SACAR LA CUENTA.
 *
 * El tendero veía lo que vendía y no lo que ganaba: no había dónde poner lo
 * que le costó cada producto ni lo que paga de luz o de arriendo.
 *
 *  · `products_business.costo`: lo que le cuesta a ESTA tienda, por la misma
 *    unidad en que lo vende (por kilo si va al peso). Es de la tienda y no
 *    del producto: el mismo arroz le cuesta distinto a cada una.
 *  · `orderssales_detail.unit_cost`: el costo CONGELADO al vender, como el
 *    precio. Si mañana le sube el arroz, lo vendido ayer no se recalcula.
 *  · `gastos_del_negocio`: luz, agua, arriendo… Los fijos se ponen una vez y
 *    cuentan cada mes; los sueltos, el día que se pagaron.
 *  · `gastos_del_mes`: el valor de un mes concreto cuando llega el recibo y
 *    no es el de siempre («la luz de este mes vino en $95.000»).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products_business', 'costo')) {
            Schema::table('products_business', function (Blueprint $t) {
                $t->decimal('costo', 12, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('orderssales_detail', 'unit_cost')) {
            Schema::table('orderssales_detail', function (Blueprint $t) {
                $t->decimal('unit_cost', 12, 2)->nullable();
            });
        }

        if (! Schema::hasTable('gastos_del_negocio')) {
            Schema::create('gastos_del_negocio', function (Blueprint $t) {
                $t->id('gasto_id');
                $t->unsignedBigInteger('busines_id')->index();
                $t->string('tipo', 20);
                $t->string('nombre', 60);
                $t->decimal('monto', 12, 2);
                // 'mensual': cuenta cada mes desde `desde`. 'una_vez': el día de `desde`.
                $t->string('frecuencia', 10)->default('mensual');
                $t->date('desde');
                // Un fijo que se dejó de pagar no se borra: se cierra, y los
                // meses de antes siguen contándolo.
                $t->date('hasta')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('gastos_del_mes')) {
            Schema::create('gastos_del_mes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('gasto_id')->index();
                $t->char('periodo', 7); // «2026-11»
                $t->decimal('monto', 12, 2);
                $t->timestamps();
                $t->unique(['gasto_id', 'periodo']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gastos_del_mes');
        Schema::dropIfExists('gastos_del_negocio');
        if (Schema::hasColumn('orderssales_detail', 'unit_cost')) {
            Schema::table('orderssales_detail', fn (Blueprint $t) => $t->dropColumn('unit_cost'));
        }
        if (Schema::hasColumn('products_business', 'costo')) {
            Schema::table('products_business', fn (Blueprint $t) => $t->dropColumn('costo'));
        }
    }
};
