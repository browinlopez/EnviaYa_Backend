<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La categoría que se le pone a lo que el archivo no clasifica.
 *
 * EL PROBLEMA: `ImportadorDeCatalogo` exige categoría para dar de alta un
 * producto que no está en el catálogo, y tiene razón —sin ella el producto no
 * sale en ninguna sección de la app y el tendero lo carga para nada—. Pero los
 * archivos que exportan los puntos de venta de las tiendas NO TIENEN columna
 * de categoría: tienen código, nombre, precio y existencias.
 *
 * El resultado era una carga de 1.010 filas con 1.010 rechazos, todos con el
 * mismo motivo. Técnicamente correcto y completamente inútil.
 *
 * Ahora el tendero elige una categoría al subir el archivo y ahí cae todo lo
 * que el archivo no clasifique. Es una respuesta honesta a «no sé»: queda
 * visible desde el primer momento, agrupado en un sitio, y se corrige después
 * producto por producto. Mejor que mil rechazos o que repartirlos a ojo.
 *
 * Nullable porque un archivo que SÍ trae la columna no la necesita, y porque
 * las cargas que ya existen no tienen ninguna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_uploads', function (Blueprint $table) {
            // `category.category_id` es INT UNSIGNED: la foránea exige el
            // mismo tipo exacto.
            $table->unsignedInteger('category_id')->nullable()->after('nombre_original');

            $table->foreign('category_id')
                ->references('category_id')->on('category')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_uploads', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
    }
};
