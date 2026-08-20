<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QUÉ SE ENTREGÓ DE VERDAD
 *
 * `recipients_count` dice a cuántas PERSONAS alcanzaba el segmento, que es una
 * intención. Estas tres columnas dicen lo que pasó al mandarlo:
 *
 *  · `devices_count`    — a cuántos teléfonos se intentó (una persona puede
 *                          tener dos, y otra ninguno registrado);
 *  · `delivered_count`  — cuántos lo aceptaron;
 *  · `failed_count`     — cuántos lo rechazaron (token muerto, app desinstalada).
 *
 * La diferencia entre destinatarios y dispositivos es el dato que más se
 * necesita y que nadie tiene al empezar: "llegó a 3.000 personas" es falso si
 * solo 800 tienen la app instalada con sesión abierta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_campaigns', function (Blueprint $table) {
            $table->unsignedInteger('devices_count')->nullable()->after('recipients_count');
            $table->unsignedInteger('delivered_count')->nullable()->after('devices_count');
            $table->unsignedInteger('failed_count')->nullable()->after('delivered_count');
        });
    }

    public function down(): void
    {
        Schema::table('push_campaigns', function (Blueprint $table) {
            $table->dropColumn(['devices_count', 'delivered_count', 'failed_count']);
        });
    }
};
