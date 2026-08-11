<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El password broker y el "remember me" del login web escriben esta
     * columna; la tabla custom `user` nunca la tuvo, así que resetear la
     * contraseña por la web reventaba al guardar. hasColumn() la hace
     * segura si algún entorno ya la agregó a mano.
     */
    public function up(): void
    {
        if (Schema::hasColumn('user', 'remember_token')) {
            return;
        }

        Schema::table('user', function (Blueprint $table) {
            $table->rememberToken()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->dropColumn('remember_token');
        });
    }
};
