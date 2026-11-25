<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ==========================================================================
 * TRES COSAS QUE TIENE CUALQUIER PRODUCTO, Y ESTABAN REPARTIDAS POR TIPO.
 *
 * Al revisar los campos de los cuatro oficios apareció el mismo dato escrito
 * tres veces con tres nombres:
 *
 *     CONTENIDO    `pharmacy_products.content`      «10 tabletas»
 *                  `grocery_products.size`          «500 g»
 *                  `restaurant_products.portion_size` «Personal»
 *
 * Es la misma pregunta —cuánto trae— contestada en tres tablas. Un arroz de
 * 500 g y un acetaminofén de 10 tabletas no son dos clases de dato: son el
 * mismo dato de dos artículos.
 *
 * Y lo mismo con el ENVASE: `pharmacy_products.presentation` existía sólo
 * para droguería, pero una lata de sardinas y una bolsa de arroz también
 * vienen en algo.
 *
 * --------------------------------------------------------------------------
 * LA REGLA QUE ESTO DEJA ESCRITA, Y QUE NO EXISTÍA EN NINGÚN SITIO:
 *
 *   · Va en `products` lo que describe el artículo IGUAL en todos los
 *     oficios. Es el catálogo COMPARTIDO: el mismo frasco trae el mismo
 *     contenido lo venda quien lo venda.
 *
 *   · Va en la tabla del tipo lo que SÓLO ese oficio pregunta. Un registro
 *     INVIMA no se le pide a un bulto de arroz.
 *
 *   · Va en `products_business` lo que cambia por tienda: precio, existencias
 *     y el vencimiento del LOTE.
 *
 * --------------------------------------------------------------------------
 * `requiere_frio` ES NUEVO, Y TAMBIÉN ES DE LOS TRES OFICIOS.
 *
 * Leche, yogurt, huevos, helado, insulina, un almuerzo caliente. Es una
 * propiedad física del artículo que le importa a quien compra —«¿llega
 * frío?»— y a quien reparte. Ponerlo por tipo sería escribirlo tres veces,
 * que es justo el error que esta migración deshace.
 *
 * Booleano con defecto y NO nulo, por lo mismo que `requiere_formula`: «no
 * sabemos» y «no necesita frío» se pintan igual, así que que sean lo mismo.
 *
 * --------------------------------------------------------------------------
 * NO SE BORRA NADA.
 *
 * Las columnas viejas se quedan donde están. Salen del esquema público —o
 * sea, dejan de pedirse y de pintarse— pero borrarlas sería una migración
 * destructiva sobre tablas que todavía no se han terminado de vaciar. Se
 * COPIA lo que hay y se deja de escribir en ellas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $tabla) {
            /*
             * 80 y 60: los mismos topes que ya tenían en `pharmacy_products`,
             * para que la copia de abajo no pueda truncar nada.
             */
            $tabla->string('content', 80)->nullable()->after('variante');
            $tabla->string('presentation', 60)->nullable()->after('content');
            $tabla->boolean('requiere_frio')->default(false)->after('presentation');
        });

        $this->copiarLoQueYaHabia();
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $tabla) {
            $tabla->dropColumn(['content', 'presentation', 'requiere_frio']);
        });
    }

    /**
     * Lo que ya estaba escrito en las tablas de tipo, al producto.
     *
     * Hoy sólo hay filas en `pharmacy_products` —46, las del Excel de
     * ejemplo—; `grocery_products` y `restaurant_products` están vacías. Aun
     * así se recorren las tres: el día que alguien corra esto sobre una base
     * donde sí hay datos, lo que no puede pasar es que se pierdan.
     */
    private function copiarLoQueYaHabia(): void
    {
        $origenes = [
            ['pharmacy_products', 'content', 'content'],
            ['pharmacy_products', 'presentation', 'presentation'],
            ['grocery_products', 'size', 'content'],
            ['restaurant_products', 'portion_size', 'content'],
        ];

        foreach ($origenes as [$tabla, $columna, $destino]) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, $columna)) {
                continue;
            }

            $filas = DB::table($tabla)
                ->whereNotNull($columna)
                ->where($columna, '<>', '')
                ->get(['products_id', $columna]);

            foreach ($filas as $f) {
                /*
                 * `whereNull` en el destino: si dos tablas de tipo tuvieran el
                 * mismo producto —no debería pasar, pero la base no lo impide—
                 * gana la primera y no se pisa en silencio.
                 */
                DB::table('products')
                    ->where('products_id', $f->products_id)
                    ->whereNull($destino)
                    ->update([$destino => $f->{$columna}]);
            }
        }
    }
};
