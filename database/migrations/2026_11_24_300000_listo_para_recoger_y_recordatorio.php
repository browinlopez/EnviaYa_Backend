<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * DOS MOMENTOS DEL PEDIDO QUE NO SE GUARDABAN.
 *
 *  · `ready_at`: cuándo la tienda dijo «ya está listo» en un pedido para
 *    recoger. Sin él no había cómo avisarle al cliente que pasara: el pedido
 *    se quedaba en «preparando» hasta que llegaba a preguntar.
 *  · `recordado_at`: cuándo se le recordó a la tienda un pedido programado.
 *    El recordatorio corre cada minuto; sin la marca lo repetiría.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            if (! Schema::hasColumn('orderssales', 'ready_at')) {
                $table->timestamp('ready_at')->nullable();
            }
            if (! Schema::hasColumn('orderssales', 'recordado_at')) {
                $table->timestamp('recordado_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->dropColumn(['ready_at', 'recordado_at']);
        });
    }
};
