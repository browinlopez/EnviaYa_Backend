<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * `business.state` era nullable y sin valor por defecto, y todos los registros
 * existentes lo tenían en NULL.
 *
 * Eso hacía imposible usarlo: el panel lo interpretaba como inactivo (0) y el
 * formulario como activo (1), y la app móvil ni siquiera lo miraba. Antes de
 * que el listado del catálogo empiece a filtrar por este campo hay que
 * normalizarlo, o el único negocio existente desaparecería de la aplicación.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Un negocio ya publicado se considera activo: es el estado en el que
        // venía funcionando de hecho.
        DB::table('business')->whereNull('state')->update(['state' => 1]);

        Schema::table('business', function (Blueprint $table) {
            $table->boolean('state')->default(1)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('business', function (Blueprint $table) {
            $table->boolean('state')->nullable()->default(null)->change();
        });
    }
};
