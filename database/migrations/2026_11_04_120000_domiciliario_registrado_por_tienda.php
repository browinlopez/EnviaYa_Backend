<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QUÉ TIENDA DIO DE ALTA AL DOMICILIARIO.
 *
 * Desde el panel de aliados la tienda ya puede crear a sus domiciliarios.
 * Los que crea ella no reparten hasta tener el acuerdo firmado y cédula,
 * licencia y SOAT cargados (ReglasDeDespacho). La regla NO aplica a los que
 * dio de alta el equipo: hoy reparten sin papeles en el sistema, y bloquearlos
 * de golpe dejaría a las tiendas sin quién despache. Esta columna es lo que
 * distingue a unos de otros. Nula = lo creó el equipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domiciliary', function (Blueprint $table) {
            $table->unsignedBigInteger('registrado_por_negocio')->nullable()->after('state');
            $table->index('registrado_por_negocio');
        });
    }

    public function down(): void
    {
        Schema::table('domiciliary', function (Blueprint $table) {
            $table->dropIndex(['registrado_por_negocio']);
            $table->dropColumn('registrado_por_negocio');
        });
    }
};
