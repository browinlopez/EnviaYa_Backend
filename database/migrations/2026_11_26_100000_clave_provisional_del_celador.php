<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LA CLAVE QUE ENTREGA EL ADMINISTRADOR ES PROVISIONAL.
 *
 * Al celador lo da de alta el administrador del conjunto y le escribe una
 * clave que los dos conocen. Hasta que el celador la cambie por una suya, lo
 * que registra en la portería podría haberlo registrado cualquiera de los dos.
 *
 * Nace en falso para todos: solo la encienden el alta de un celador y el
 * restablecimiento de su clave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user', function (Blueprint $t) {
            $t->boolean('must_change_password')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('user', fn (Blueprint $t) => $t->dropColumn('must_change_password'));
    }
};
