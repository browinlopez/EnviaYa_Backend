<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El plazo de entrega prometido, congelado en cada pedido.
 *
 * Se guarda acá y no se lee del ajuste al consultar por la misma razón que ya
 * se hace con `domicilio` y `domiciliary_fee`: si el estándar se lee en el
 * momento de mirar el informe, cambiarlo en el panel reescribe el rendimiento
 * pasado de todos los domiciliarios. Subir el plazo de 20 a 30 minutos
 * convertiría en "a tiempo" entregas que llegaron tarde, y nadie podría saber
 * contra qué se les midió.
 *
 * Se sella al despachar, que es cuando empieza a correr el reloj.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            if (!Schema::hasColumn('orderssales', 'promised_minutes')) {
                $table->unsignedSmallInteger('promised_minutes')
                    ->nullable()
                    ->after('dispatched_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            if (Schema::hasColumn('orderssales', 'promised_minutes')) {
                $table->dropColumn('promised_minutes');
            }
        });
    }
};
