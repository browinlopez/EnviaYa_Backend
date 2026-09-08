<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LA PROMOCIÓN PASA DE AVISO A DESCUENTO
 *
 * Nació como un texto que el tendero cumplía en el mostrador. Los tenderos
 * pidieron que se aplicara de verdad, así que ahora cada una lleva una REGLA
 * que el servidor sabe calcular.
 *
 * SOLO DOS TIPOS, y esa es la decisión que mantiene la pantalla usable:
 *
 *   · `porcentaje` — «20% de descuento». `percentage_discount` guarda el 0.20.
 *   · `nxm`        — «lleva 3, paga 2». `lleva` y `paga` guardan los números.
 *
 * Con estos dos se expresan las cuatro frases de arranque que ya existían:
 * «2x1» es nxm(2,1), «compra 2 y el tercero gratis» es nxm(3,2), y «20% de
 * descuento» y «precio especial» son porcentajes.
 *
 * `ninguno` sigue existiendo: es la promoción que solo avisa —«traemos pan
 * caliente a las 4»— y no toca ningún precio.
 *
 * NO HAY UN TIPO «PRECIO FIJO» ni umbrales por monto. Cada tipo nuevo es un
 * campo más en una pantalla pensada para quien no maneja formularios, y estos
 * dos cubren lo que se pidió. El día que haga falta otro, se añade acá.
 *
 * `percentage_discount` ya existía en la tabla desde 2025, sin usar. Se
 * reutiliza en vez de crear una columna que diría lo mismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            if (!Schema::hasColumn('promotions', 'tipo')) {
                $table->string('tipo', 20)->default('ninguno')->after('description');
            }

            /*
             * «Lleva N y paga M». Enteros pequeños y con tope en la
             * validación: un «lleva 100 paga 1» no es una promoción, es un
             * error de dedo que regala el inventario.
             */
            if (!Schema::hasColumn('promotions', 'lleva')) {
                $table->unsignedTinyInteger('lleva')->nullable()->after('percentage_discount');
            }

            if (!Schema::hasColumn('promotions', 'paga')) {
                $table->unsignedTinyInteger('paga')->nullable()->after('lleva');
            }
        });

        /*
         * Al buscar las promociones que aplican a un carrito se pregunta
         * siempre por lo mismo: las de ESTE negocio que están enviadas. Sin
         * índice eso es un recorrido de la tabla entera en cada pedido.
         */
        Schema::table('promotions', function (Blueprint $table) {
            $table->index(['busines_id', 'state'], 'promo_negocio_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropIndex('promo_negocio_estado_idx');

            foreach (['tipo', 'lleva', 'paga'] as $columna) {
                if (Schema::hasColumn('promotions', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
