<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El conjunto pasa a tener ficha propia.
 *
 * Hasta ahora `residential_complexes` guardaba lo mínimo para ubicarlo en el
 * mapa y contar unidades. Un conjunto es un cliente de la plataforma —con su
 * administración, su portería y su gente— y no tenía ni cómo llamar a quien lo
 * administra.
 *
 * `photo` es la que se pidió: una foto de la fachada. No es decoración. En un
 * panel donde el celador de un edificio y el de otro ven exactamente la misma
 * pantalla azul, la foto es lo único que responde de un vistazo «¿estoy en el
 * conjunto correcto?» — y ese error, en un panel de portería, deja entrar a
 * alguien al edificio equivocado.
 *
 * Los datos de contacto de la administración son de la ADMINISTRACIÓN, no de
 * un residente: el teléfono de la portería, el correo del consejo. Nada de
 * esto son datos personales de vecinos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residential_complexes', function (Blueprint $table) {
            // Ruta relativa dentro del disco público, como `business.logo`.
            $table->string('photo')->nullable()->after('name');

            $table->string('phone', 40)->nullable()->after('address');
            $table->string('email', 120)->nullable()->after('phone');
            /* Cómo se llama quien administra, para que la plataforma sepa a
               quién llamar. Distinto de la CUENTA del panel: la persona cambia
               más veces que el correo con el que entra. */
            $table->string('admin_name', 120)->nullable()->after('email');
            $table->string('nit', 40)->nullable()->after('admin_name');

            /* Notas de la portería: instrucciones permanentes que el celador
               tiene que tener a la vista. "Después de las 10 p.m. no se
               reciben domicilios", "la torre 7 no tiene ascensor". */
            $table->text('gate_notes')->nullable()->after('nit');

            /*
             * Si la portería exige que el residente autorice cada visita.
             *
             * Con valor por defecto en falso —que es como funciona hoy— para
             * que activarlo sea una decisión y no algo que aparece solo tras
             * la migración y bloquea la puerta de un edificio.
             */
            $table->boolean('require_authorization')->default(false)->after('gate_notes');
        });
    }

    public function down(): void
    {
        Schema::table('residential_complexes', function (Blueprint $table) {
            $table->dropColumn([
                'photo', 'phone', 'email', 'admin_name', 'nit',
                'gate_notes', 'require_authorization',
            ]);
        });
    }
};
