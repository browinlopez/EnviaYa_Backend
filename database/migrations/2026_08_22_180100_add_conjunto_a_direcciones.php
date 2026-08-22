<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La dirección por fin sabe si está dentro de un conjunto.
 *
 * No había NINGÚN vínculo entre `user_address` y `residential_complexes`: la
 * dirección de quien vive en un conjunto era indistinguible de cualquier otra.
 * Por eso no se podía responder "cuánto vendimos en este conjunto" sin pasar
 * por el comprador, que es peor dato — alguien puede pedir a otra dirección.
 *
 * `tower` y `apartment` van como texto y no como número a propósito: hay
 * conjuntos con "Torre A" y apartamentos como "502B". Guardarlos como enteros
 * obligaría a inventar una traducción y perdería lo que la persona escribió.
 *
 * `street` sigue siendo para la dirección escrita a mano de quien NO vive en
 * conjunto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_address', function (Blueprint $table) {
            if (!Schema::hasColumn('user_address', 'complex_id')) {
                // `residential_complexes.complex_id` es bigint: acá sí coincide.
                $table->unsignedBigInteger('complex_id')->nullable()->after('street');
                $table->foreign('complex_id')
                    ->references('complex_id')->on('residential_complexes')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('user_address', 'tower')) {
                $table->string('tower', 40)->nullable()->after('complex_id');
            }

            if (!Schema::hasColumn('user_address', 'apartment')) {
                $table->string('apartment', 40)->nullable()->after('tower');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_address', function (Blueprint $table) {
            if (Schema::hasColumn('user_address', 'complex_id')) {
                $table->dropForeign(['complex_id']);
                $table->dropColumn('complex_id');
            }

            foreach (['apartment', 'tower'] as $c) {
                if (Schema::hasColumn('user_address', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
