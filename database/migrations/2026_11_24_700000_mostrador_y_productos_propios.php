<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOS COSAS DEL MOSTRADOR DE VERDAD.
 *
 * 1. LA VENTA EN PERSONA. El vecino que entra y pide «$1.500 de queso» no
 *    pasa por la app, pero es plata de la tienda: sin registrarla, «Cuánto te
 *    queda» solo ve una parte del negocio. Va en tablas PROPIAS y no en
 *    `orderssales` a propósito: un pedido arrastra comprador, domiciliario,
 *    pagos, liquidaciones, avisos, informes y el panel, y una venta de
 *    mostrador no tiene nada de eso. Meterla allí habría sido abrir veinte
 *    sitios a una fila sin comprador.
 *
 * 2. EL PRODUCTO QUE NO ESTÁ EN EL CATÁLOGO. «Hormiga culona, $3.000»: el
 *    cliente lo pide, la tienda lo tiene y el catálogo no. El tendero lo crea
 *    con nombre y precio y lo vende YA, solo en su tienda; el equipo lo
 *    completa después desde el panel y decide si pasa al catálogo general.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventas_mostrador', function (Blueprint $t) {
            $t->id('venta_id');
            $t->unsignedInteger('busines_id')->index();
            $t->unsignedInteger('user_id')->nullable();
            $t->decimal('total', 10, 2);
            $t->string('forma_de_pago', 20)->default('efectivo');
            $t->decimal('cash_tendered', 10, 2)->nullable();
            $t->decimal('change_given', 10, 2)->nullable();
            $t->timestamp('vendida_at');
            $t->timestamps();

            $t->index(['busines_id', 'vendida_at']);
        });

        Schema::create('ventas_mostrador_lineas', function (Blueprint $t) {
            $t->id('linea_id');
            $t->foreignId('venta_id')->constrained('ventas_mostrador', 'venta_id')->cascadeOnDelete();
            $t->unsignedBigInteger('products_id');
            $t->string('nombre');
            $t->string('sale_unit', 8)->default('unidad');
            $t->string('pedido_por', 10)->nullable();
            // En la unidad de venta: 3 unidades, 0,075 kg.
            $t->decimal('cantidad', 10, 3);
            $t->decimal('unit_price', 10, 2);
            // Congelado al vender, igual que en los pedidos.
            $t->decimal('unit_cost', 10, 2)->nullable();
            $t->decimal('line_total', 10, 2);
        });

        Schema::table('products', function (Blueprint $t) {
            // La tienda que lo creó. Null en todo lo del catálogo de siempre.
            $t->unsignedInteger('busines_id_propietario')->nullable()->index()->after('origen');
            // Si lo ven las demás tiendas al buscar. Lo de siempre, sí.
            $t->boolean('en_catalogo_general')->default(true)->after('busines_id_propietario');
            // Cuándo lo revisó el equipo. Null = pendiente en la bandeja.
            $t->timestamp('revisado_at')->nullable()->after('en_catalogo_general');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropIndex(['busines_id_propietario']);
            $t->dropColumn(['busines_id_propietario', 'en_catalogo_general', 'revisado_at']);
        });
        Schema::dropIfExists('ventas_mostrador_lineas');
        Schema::dropIfExists('ventas_mostrador');
    }
};
