<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `products_business` no tenía un solo índice.
 *
 * La migración original crea la clave primaria `busines_products_id` y nada
 * más: ni `busines_id`, ni `products_id`, ni un único sobre la pareja. Dos
 * consecuencias, y la primera es de integridad.
 *
 * QUE UN PRODUCTO NO SE REPITA EN UNA TIENDA lo cuidaba un `exists()` en PHP
 * dentro del importador de Excel. Eso es una condición de carrera de manual:
 * dos cargas a la vez consultan, las dos no encuentran nada, las dos insertan.
 * Y con el importador nuevo —que actualiza en vez de duplicar— el único deja de
 * ser una defensa y pasa a ser el mecanismo: es lo que hace que `upsert`
 * funcione.
 *
 * LO OTRO ES QUE LA CONSULTA MÁS FRECUENTE DE LA PLATAFORMA recorre la tabla
 * entera. «Dame el catálogo de esta tienda» filtra por `busines_id`, y sin
 * índice eso es un escaneo completo. Con 547 filas no se nota; con doscientas
 * tiendas de cuatrocientos productos son ochenta mil, y se nota en cada
 * apertura de una ficha de negocio.
 *
 * Antes de crear el único se limpian los duplicados que pudiera haber, porque
 * si los hay la migración falla y deja la base a medias. Se conserva la fila de
 * `busines_products_id` más bajo: es la primera que se creó, y las siguientes
 * son el resultado de haber vuelto a subir el mismo archivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicados = DB::table('products_business')
            ->select('busines_id', 'products_id', DB::raw('MIN(busines_products_id) as se_queda'))
            ->groupBy('busines_id', 'products_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicados as $d) {
            DB::table('products_business')
                ->where('busines_id', $d->busines_id)
                ->where('products_id', $d->products_id)
                ->where('busines_products_id', '!=', $d->se_queda)
                ->delete();
        }

        Schema::table('products_business', function (Blueprint $table) {
            $table->unique(['busines_id', 'products_id'], 'uq_producto_por_negocio');

            // El catálogo de una tienda, que es la consulta de cada ficha.
            $table->index('busines_id', 'ix_pb_negocio');

            // «En cuántas tiendas se vende esto», que es lo que decide si el
            // tendero puede tocar el nombre.
            $table->index('products_id', 'ix_pb_producto');
        });
    }

    public function down(): void
    {
        Schema::table('products_business', function (Blueprint $table) {
            $table->dropUnique('uq_producto_por_negocio');
            $table->dropIndex('ix_pb_negocio');
            $table->dropIndex('ix_pb_producto');
        });
    }
};
