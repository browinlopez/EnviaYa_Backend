<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `user_address` TENÍA DOS COLUMNAS PARA EL MISMO DATO.
 *
 *   `municipalities_id`  — la original, de la migración de 2025. Ninguna línea
 *                          de código la lee ni la escribe. 1 fila con valor.
 *   `municipality_id`    — la que se usa: 16 archivos, 12 filas con valor.
 *
 * Alguien añadió la segunda con el nombre en singular —que es el que sigue la
 * convención del resto del proyecto: `business.municipality_id`,
 * `residential_complexes.municipality_id`— y nadie retiró la primera.
 *
 * El riesgo de dejarlas es concreto: dos columnas con el mismo significado y
 * distinto contenido acaban usándose las dos, y entonces la dirección dice dos
 * ciudades distintas según quién pregunte.
 *
 * Antes de borrar se rescata el único valor que tenía y que la columna buena no
 * tenía: la dirección #1 quedaría sin municipio, y sin municipio la búsqueda de
 * direcciones no sabe en qué ciudad está «calle 18 carrera 37b».
 *
 * De paso, la que se queda pasa a tener foránea. No la tenía ninguna de las
 * dos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('user_address', 'municipalities_id')) {
            return;
        }

        // Lo que sólo estaba en la columna vieja, a la buena.
        DB::table('user_address')
            ->whereNull('municipality_id')
            ->whereNotNull('municipalities_id')
            ->update(['municipality_id' => DB::raw('municipalities_id')]);

        Schema::table('user_address', function (Blueprint $table) {
            $table->dropColumn('municipalities_id');
        });

        /*
         * Y la foránea que faltaba. `nullOnDelete` y no cascade: si algún día
         * se retira un municipio del catálogo, la dirección sigue siendo la
         * dirección de alguien — pierde la ciudad, no la calle.
         */
        Schema::table('user_address', function (Blueprint $table) {
            $table->foreign('municipality_id', 'fk_direccion_municipio')
                ->references('id')->on('municipalities')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_address', function (Blueprint $table) {
            $table->dropForeign('fk_direccion_municipio');
            $table->unsignedBigInteger('municipalities_id')->nullable();
        });
    }
};
