<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VENDER POR PESO.
 *
 * El peso no existía en la plataforma. Ni unidad de medida, ni precio por
 * kilo, ni decimales: `amount` es un entero en dos tablas y hay veintidós
 * sitios que lo dan por hecho —desde el descuento de inventario hasta lo que
 * se le manda a la pasarela—. Una tienda de barrio vende queso, tomate y
 * carne al peso, así que la mitad de su surtido no cabía.
 *
 * `amount` NO SE MIGRA A DECIMAL, y es la decisión que sostiene todo lo
 * demás. Cambiarle el tipo es lo más peligroso que se podía hacer acá: nada
 * dejaría de compilar y veinte sitios cambiarían de significado en silencio
 * —`decrement('amount', (int) …)` seguiría truncando 1,8 kg a 1, `SUM(amount)`
 * seguiría mezclando kilos con unidades—. Con columnas nuevas, el código que
 * no se enteró del cambio SIGUE SIENDO CORRECTO, porque solo opera sobre
 * productos cuya unidad es `unidad`.
 *
 * Y el total NO cambia al pesar. En la tienda se pide de dos maneras y las
 * dos fijan el precio en el momento del pedido: «3.000 de queso» —el cliente
 * pone la plata y el peso es la variable— o «500 g de tomate» —el cliente
 * pone el peso—. Lo que aceptó es lo que paga; el tendero pesa para acercarse
 * y lo que registra queda para el inventario y para el control. Por eso no
 * hace falta recalcular totales, bloquear la pasarela ni devolver
 * diferencias.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ---------------- LO QUE EL TENDERO CONFIGURA ---------------- */
        Schema::table('products_business', function (Blueprint $table) {
            /*
             * El interruptor de todo. `varchar` y no un ENUM de MySQL: añadir
             * «gramo» a un ENUM es un ALTER sobre la tabla de precios de todas
             * las tiendas. La lista vive en `App\Support\UnidadDeVenta`.
             *
             * El default deja correctas las filas de hoy sin un solo UPDATE.
             */
            $table->string('sale_unit', 8)->default('unidad')->after('price');

            /*
             * Existencias cuando NO se vende por unidad. Columna aparte de
             * `amount` a propósito: así `ExistenciasDelPedido` nunca tiene que
             * decidir cuál de las dos tocar en el mismo renglón, y el
             * `(int)` que trunca deja de estar en el camino del peso.
             */
            $table->decimal('stock_weight', 10, 3)->default(0)->after('amount');

            // Mínimo vendible (0.250) y el escalón de los botones de porción.
            $table->decimal('min_qty', 8, 3)->nullable()->after('stock_weight');
            $table->decimal('step_qty', 8, 3)->nullable()->after('min_qty');
        });

        /* ---------------- LA LÍNEA DEL PEDIDO ---------------- */
        Schema::table('orderssales_detail', function (Blueprint $table) {
            /*
             * CONGELADA EN LA LÍNEA, igual que `unit_price`. Si el tendero
             * cambia mañana ese producto de unidad a kilo, los pedidos de ayer
             * no se reinterpretan.
             */
            $table->string('sale_unit', 8)->default('unidad')->after('unit_price');

            // `valor` = «3.000 de queso» · `cantidad` = «500 g de tomate».
            $table->string('pedido_por', 8)->nullable()->after('sale_unit');

            $table->decimal('qty_requested', 10, 3)->nullable()->after('pedido_por');

            /*
             * Lo que pesó el tendero. SEPARADO de lo pedido, y no una sola
             * columna: con una sola no hay con qué decirle al comprador
             * «pediste ≈1 kg, pesó 1,18», no hay tope de desvío posible, y no
             * hay forma de ver a un tendero que pesa sistemáticamente de más.
             * Ese par es el único contrapeso a que el peso lo ponga una sola
             * persona.
             */
            $table->decimal('qty_final', 10, 3)->nullable()->after('qty_requested');

            /*
             * EL IMPORTE DE LÍNEA, CONGELADO. Hoy no está escrito en ningún
             * sitio: se recalcula como `unit_price * amount` en cada
             * consumidor —el carrito, la factura, el panel—. Con peso, y sobre
             * todo con un pedido «por plata» donde el importe ES el dato, eso
             * tiene que quedar guardado una vez.
             *
             * NULL en las líneas de unidad, y no se rellena hacia atrás: dos
             * fuentes de verdad del mismo importe, una de ellas con quince mil
             * filas históricas sin verificar, es peor que una regla de lectura
             * de una línea. Ver `App\Support\ImporteDeLinea`.
             */
            $table->decimal('line_total', 10, 2)->nullable()->after('qty_final');

            $table->timestamp('weighed_at')->nullable()->after('line_total');
            $table->unsignedBigInteger('weighed_by')->nullable()->after('weighed_at');
        });

        /* ---------------- LA CABECERA ---------------- */
        Schema::table('orderssales', function (Blueprint $table) {
            /*
             * 0 no aplica · 1 por pesar · 2 pesado.
             *
             * UNA DIMENSIÓN APARTE, no un `state` nuevo. Un estado de pedido
             * nuevo rompería la lista de estados del panel, las columnas del
             * tablero, `puedeMoverPedido`, `ReglasDeDespacho`, los informes
             * que filtran por estado y las dos apps YA PUBLICADAS en las
             * tiendas, que no se pueden actualizar a voluntad.
             */
            $table->tinyInteger('weighing_state')->default(0)->after('cash_tendered');
            $table->timestamp('weighed_at')->nullable()->after('weighing_state');
        });
    }

    public function down(): void
    {
        Schema::table('products_business', function (Blueprint $table) {
            $table->dropColumn(['sale_unit', 'stock_weight', 'min_qty', 'step_qty']);
        });

        Schema::table('orderssales_detail', function (Blueprint $table) {
            $table->dropColumn([
                'sale_unit', 'pedido_por', 'qty_requested',
                'qty_final', 'line_total', 'weighed_at', 'weighed_by',
            ]);
        });

        Schema::table('orderssales', function (Blueprint $table) {
            $table->dropColumn(['weighing_state', 'weighed_at']);
        });
    }
};
