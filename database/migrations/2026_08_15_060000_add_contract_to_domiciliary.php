<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acuerdo de vinculación firmado por el domiciliario.
 *
 * El PDF vive en R2 como un archivo más de la persona (`media_files`); acá se
 * guarda solo el vínculo y la fecha, que son lo que hace falta para responder
 * "¿este repartidor tiene contrato?" sin recorrer el bucket.
 *
 * `contract_city` se conserva porque el documento dice "se firma en la ciudad
 * de ___": si más adelante hay que reexpedirlo, tiene que salir igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domiciliary', function (Blueprint $table) {
            $table->unsignedBigInteger('contract_media_id')->nullable()->after('document');
            $table->timestamp('contract_signed_at')->nullable()->after('contract_media_id');
            $table->string('contract_city', 120)->nullable()->after('contract_signed_at');

            $table->foreign('contract_media_id')
                ->references('id')->on('media_files')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('domiciliary', function (Blueprint $table) {
            $table->dropForeign(['contract_media_id']);
            $table->dropColumn(['contract_media_id', 'contract_signed_at', 'contract_city']);
        });
    }
};
