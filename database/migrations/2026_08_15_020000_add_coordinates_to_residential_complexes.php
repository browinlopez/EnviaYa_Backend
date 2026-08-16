<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los conjuntos residenciales tienen dirección pero no coordenadas, así que
 * no se pueden ubicar en el mapa de entregas ni medir distancias contra los
 * negocios cercanos. Se añaden con la misma precisión que `business`
 * (decimal 10,7 ≈ 1 cm), y el municipio para poder acotar la búsqueda de
 * direcciones igual que en negocios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residential_complexes', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedBigInteger('municipality_id')->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('residential_complexes', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'municipality_id']);
        });
    }
};
