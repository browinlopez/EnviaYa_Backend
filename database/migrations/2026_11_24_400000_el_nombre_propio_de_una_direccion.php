<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UNA DIRECCIÓN CON EL NOMBRE QUE LE DA LA GENTE.
 *
 * Había tres etiquetas --Casa, Trabajo, Otros-- y quien pedía a casa de la
 * mamá o del novio la guardaba como «Otros», y con dos «Otros» no se sabía
 * cuál era cuál. Ahora hay dos etiquetas más (Familia, Pareja) y un nombre
 * libre opcional: «Donde mi mamá», «Apto de Laura».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('user_address', 'nombre')) {
            Schema::table('user_address', function (Blueprint $table) {
                $table->string('nombre', 40)->nullable();
            });
        }

        if (Schema::hasTable('alias')) {
            foreach (['Familia', 'Pareja'] as $nombre) {
                if (! DB::table('alias')->where('name', $nombre)->exists()) {
                    DB::table('alias')->insert(['name' => $nombre]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_address', 'nombre')) {
            Schema::table('user_address', function (Blueprint $table) {
                $table->dropColumn('nombre');
            });
        }
    }
};
