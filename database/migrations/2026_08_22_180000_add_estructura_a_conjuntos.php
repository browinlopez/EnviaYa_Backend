<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuántas torres tiene un conjunto y cuántos apartamentos cada una.
 *
 * Hasta ahora un conjunto era una fila plana —nombre, dirección y un punto en
 * el mapa— sin ninguna noción de su interior. Quien vivía en uno guardaba
 * "Torre 3 Apto 502" como texto libre dentro de la dirección, si acaso, y por
 * eso no se podía ni contar ni filtrar nada por torre.
 *
 * SE ASUME QUE TODAS LAS TORRES SON IGUALES, y es una simplificación
 * consciente: un conjunto con una torre de 20 apartamentos y otra de 12 va a
 * quedar mal representado, y alguien podrá elegir un apartamento que no
 * existe. Se acepta porque el dato sirve sobre todo para dimensionar —cuántas
 * unidades hay— y para ofrecer una lista en el registro, no para validar
 * direcciones.
 *
 * Para que eso se pueda corregir después sin migrar nada, la dirección guarda
 * la torre y el apartamento como VALORES propios y no como un índice contra
 * estas cuentas. El día que haga falta una tabla de torres, las direcciones ya
 * escritas siguen siendo válidas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residential_complexes', function (Blueprint $table) {
            if (!Schema::hasColumn('residential_complexes', 'towers_count')) {
                $table->unsignedSmallInteger('towers_count')->nullable()->after('people_count');
            }

            if (!Schema::hasColumn('residential_complexes', 'apartments_per_tower')) {
                $table->unsignedSmallInteger('apartments_per_tower')->nullable()->after('towers_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('residential_complexes', function (Blueprint $table) {
            foreach (['apartments_per_tower', 'towers_count'] as $c) {
                if (Schema::hasColumn('residential_complexes', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
