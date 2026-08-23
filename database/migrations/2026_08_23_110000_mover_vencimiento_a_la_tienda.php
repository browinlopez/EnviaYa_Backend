<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La fecha de vencimiento es del lote de CADA TIENDA, no del producto.
 *
 * `grocery_products.expiration_date` y `pharmacy_products.expiration_date`
 * cuelgan de `products_id`, o sea del catálogo compartido. Pero el atún de la
 * tienda de la esquina vence en marzo y el de la de tres cuadras más allá en
 * septiembre: es un dato del lote que tiene cada una, no del producto.
 *
 * Hoy hay 145 productos que se venden en más de un negocio. En todos ellos, la
 * fecha que escribiera una tienda se la escribía a las demás. En abarrotes es
 * un error molesto; en droguería es otra cosa.
 *
 * SE MUEVE A `products_business`, que es donde vive lo que es de cada tienda
 * —el precio y las existencias— y donde ya se sabía que tenía que estar.
 *
 * NO SE BORRA LA COLUMNA VIEJA en esta migración. Las versiones de la app que
 * están en los teléfonos siguen leyendo `grocery.expiration_date` y quitarla
 * las revienta; se copia el valor a cada tienda que vende el producto y la
 * columna original queda como estaba hasta que la mayoría haya actualizado.
 * Copiar es lo correcto: si el producto solo lo vende una tienda —que es el
 * caso de la mayoría— la fecha era suya de todos modos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products_business', function (Blueprint $table) {
            $table->date('expiration_date')->nullable()->after('amount');
        });

        foreach (['grocery_products', 'pharmacy_products'] as $tabla) {
            if (!Schema::hasTable($tabla) || !Schema::hasColumn($tabla, 'expiration_date')) {
                continue;
            }

            /*
             * Subconsulta correlacionada y no UPDATE con JOIN.
             *
             * MySQL admite el JOIN, pero las pruebas corren sobre SQLite y ahí
             * Laravel lo reescribe a un subselect donde el alias de la tabla
             * unida ya no existe. Esta forma la entienden los dos, y sigue
             * siendo una sola sentencia: son 587 productos por las tiendas que
             * los venden, y fila por fila un despliegue tardaría un minuto.
             */
            $fecha = DB::table($tabla)
                ->select('expiration_date')
                ->whereColumn($tabla . '.products_id', 'products_business.products_id')
                ->whereNotNull('expiration_date')
                ->limit(1);

            DB::table('products_business')
                ->whereExists(fn ($q) => $q->selectRaw(1)->from($tabla)
                    ->whereColumn($tabla . '.products_id', 'products_business.products_id')
                    ->whereNotNull($tabla . '.expiration_date'))
                ->update(['expiration_date' => DB::raw('(' . $fecha->toSql() . ')')]);
        }
    }

    public function down(): void
    {
        Schema::table('products_business', function (Blueprint $table) {
            $table->dropColumn('expiration_date');
        });
    }
};
