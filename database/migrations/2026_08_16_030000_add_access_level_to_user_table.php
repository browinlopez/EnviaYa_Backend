<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nivel de acceso DENTRO del área.
 *
 * El área dice QUÉ SECCIONES le tocan a alguien; esto dice QUÉ PUEDE HACER en
 * ellas. Un auxiliar de contabilidad y el contador miran las mismas pantallas,
 * pero solo uno aprueba liquidaciones.
 *
 * Se resuelve con una columna y no duplicando áreas ("Marketing" y "Marketing
 * consulta") porque esa vía convierte siete áreas en catorce y obliga a marcar
 * cada módulo nuevo dos veces, con el riesgo de que las dos copias se
 * desincronicen y nadie note cuál quedó mal.
 *
 * DEFECTO: `consulta`.
 * Quien entra nuevo mira y no toca hasta que alguien lo decida a propósito. El
 * error de dar de menos se descubre enseguida —la persona lo pide— mientras
 * que el de dar de más solo se descubre cuando ya se borró algo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->enum('access_level', ['gestor', 'consulta'])
                ->default('consulta')
                ->after('area_id');
        });

        /*
         * Quien YA trabajaba en el panel conserva lo que tenía.
         *
         * Sin esto, el defecto seguro dejaría a todo el equipo en solo lectura
         * de un día para otro, incluida la única cuenta capaz de repartir
         * permisos. El defecto prudente aplica a quien llegue después, no a
         * quien ya estaba.
         */
        DB::table('user')->where('rol', 4)->update(['access_level' => 'gestor']);
    }

    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->dropColumn('access_level');
        });
    }
};
