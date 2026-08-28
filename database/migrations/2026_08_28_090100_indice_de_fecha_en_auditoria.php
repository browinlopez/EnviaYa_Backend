<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LA AUDITORÍA SE CONSULTA POR FECHA Y NO TENÍA ÍNDICE DE FECHA.
 *
 * `audits` traía los dos índices que pone el paquete —por registro auditado y
 * por autor— y ninguno por `created_at`. Pero la pantalla de **Control →
 * Auditoría** ordena por fecha y filtra por periodo, que es como se usa un
 * registro de auditoría: «qué pasó ayer», «qué tocó esta persona la semana
 * pasada».
 *
 * Con 269 filas da igual. La tabla pesa 464 kB, o sea **1,7 kB por fila** —lo
 * normal, porque cada una guarda el antes y el después en JSON—, así que a mil
 * cambios al día son unos 600 MB al año, y ahí ordenar sin índice se nota.
 *
 * Se añade compuesto `(created_at, auditable_type)` y no suelto: la pantalla
 * casi siempre acota además por tipo de registro, y el prefijo izquierdo sigue
 * sirviendo para ordenar sólo por fecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            $table->index(['created_at', 'auditable_type'], 'ix_auditoria_fecha');
        });
    }

    public function down(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            $table->dropIndex('ix_auditoria_fecha');
        });
    }
};
