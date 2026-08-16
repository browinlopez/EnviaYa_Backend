<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A qué área pertenece cada persona del equipo.
 *
 * Nulo para la inmensa mayoría: compradores, tenderos y domiciliarios no son
 * personal de la empresa y no tienen área. Solo quien entra al panel la lleva.
 *
 * `nullOnDelete` y no `cascade`: borrar un área nunca puede borrar personas.
 * El usuario se queda sin acceso al panel hasta que se le asigne otra, que es
 * exactamente lo que debe pasar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->unsignedBigInteger('area_id')->nullable()->after('rol');

            $table->foreign('area_id')
                ->references('id')->on('areas')
                ->nullOnDelete();
        });

        /*
         * Los administradores que ya existían pasan a Tecnología.
         *
         * Sin esto, en el momento en que el middleware empiece a exigir área
         * quedarían fuera de su propio panel —incluida la única cuenta capaz de
         * repartir permisos— y habría que arreglarlo a mano contra la base.
         */
        $sistema = DB::table('areas')->where('code', 'sistema')->value('id');

        if ($sistema) {
            DB::table('user')->where('rol', 4)->update(['area_id' => $sistema]);
        }
    }

    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->dropForeign(['area_id']);
            $table->dropColumn('area_id');
        });
    }
};
