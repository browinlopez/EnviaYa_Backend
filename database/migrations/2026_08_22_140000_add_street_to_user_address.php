<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La calle que escribe la persona tenía dónde escribirse y no dónde guardarse.
 *
 * El formulario de dirección pide "Calle" —"Ej. Carrera 10 #20-30"—, la app la
 * manda como `street`, y ni la tabla tenía la columna ni el controlador la
 * miraba: se perdía en silencio. Lo único que quedaba guardado era la dirección
 * que devuelve el mapa a partir del pin.
 *
 * Y esa no basta para entregar. El geocodificador da una aproximación —"Calle
 * 84, Barranquilla"—; el número de casa o apartamento lo pone la persona, y es
 * justo lo que el domiciliario necesita para llegar a la puerta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_address', function (Blueprint $table) {
            $table->string('street', 150)->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('user_address', function (Blueprint $table) {
            $table->dropColumn('street');
        });
    }
};
