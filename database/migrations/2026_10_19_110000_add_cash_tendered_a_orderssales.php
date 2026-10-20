<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CON CUÁNTO VA A PAGAR EL CLIENTE.
 *
 * Hoy el tendero no lo sabe, y es un problema de todos los días: una compra de
 * $12.000 pagada con un billete de $50.000 necesita $38.000 de cambio, y eso
 * hay que tenerlo ANTES de que el domiciliario salga. Cuando no se sabe, el
 * domiciliario llega a la puerta, no tiene con qué devolver, y la venta se
 * cae en el último metro o el cliente se queda esperando a que alguien vaya a
 * buscar sencillo.
 *
 * `NULL` significa «no lo dijo», que es distinto de cero y es lo que tendrán
 * todos los pedidos de hasta ahora. Es un dato OPCIONAL a propósito: obligar a
 * declararlo para poder pedir sería poner una puerta donde no hacía falta.
 *
 * EL CAMBIO NO SE GUARDA. Es `cash_tendered - total` y se calcula al
 * enseñarlo: el total puede moverse —una promoción que se agota entre el
 * carrito y el pago, o un pedido que se corrige— y una cifra congelada
 * acabaría diciendo un número que ya no es verdad, que es peor que no decir
 * ninguno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            /*
             * Al lado de `cash_due`, que es la otra cara del mismo asunto: lo
             * que el domiciliario quedó debiendo por cobrar en efectivo.
             */
            $table->decimal('cash_tendered', 10, 2)->nullable()->after('cash_due');
        });
    }

    public function down(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->dropColumn('cash_tendered');
        });
    }
};
