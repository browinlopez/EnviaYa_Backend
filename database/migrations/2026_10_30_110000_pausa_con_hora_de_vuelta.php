<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LA PAUSA TIENE HORA DE VUELTA
 *
 * El diseño aprobado no dice «Tienda en pausa»: dice «Pausada hasta 3:00
 * p. m.», y esa diferencia no es de redacción. Una pausa sin final depende de
 * que el tendero se acuerde de encenderla, y el día que se le olvide no se va
 * a quejar nadie: simplemente deja de entrarle gente. Con hora de vuelta, el
 * olvido se corrige solo.
 *
 * NO HAY TAREA PROGRAMADA que vaya encendiendo tiendas. La hora se compara al
 * leer —`Business::recibiendoAhora()` y el scope `recibiendo()`—, que es
 * correcto siempre y a la misma hora para todo el mundo. Un comando que
 * recorre la tabla cada minuto sería otra cosa que puede caerse, y mientras
 * esté caída las tiendas se quedan apagadas sin motivo.
 *
 * Nula significa «sin hora»: el tendero pausa sin saber cuándo vuelve, que
 * también es un caso real —se fue la luz— y entonces sí tiene que encenderla
 * él.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business', function (Blueprint $table) {
            $table->timestamp('accepting_until')->nullable()->after('accepting_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('business', function (Blueprint $table) {
            $table->dropColumn('accepting_until');
        });
    }
};
