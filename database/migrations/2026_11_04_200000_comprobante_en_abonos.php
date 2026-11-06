<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El comprobante de un abono: la foto de la transferencia o del recibo.
 *
 * Un abono sin soporte es la palabra del tendero contra la del cliente el día
 * que discutan cuánto se pagó. Se guarda el archivo en `media_files`, como
 * los papeles del domiciliario, y acá solo su id. Opcional: el que paga en
 * efectivo en el mostrador no trae papel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_credit_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('comprobante_media_id')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('store_credit_movements', function (Blueprint $table) {
            $table->dropColumn('comprobante_media_id');
        });
    }
};
