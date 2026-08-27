<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QUIÉN VERIFICÓ ESTE CORREO.
 *
 * Hasta ahora `email_verified_at` significaba una sola cosa, y una cosa muy
 * concreta: le mandamos un enlace a esa dirección, llegó, y la persona lo
 * abrió. Era una prueba de que el correo EXISTE y es suyo.
 *
 * Al darle al panel un botón para verificar a mano, ese significado se pierde:
 * la misma insignia verde pasaría a cubrir dos hechos muy distintos —uno
 * comprobado y otro afirmado por un administrador— sin manera de saber cuál es
 * cuál. Y la diferencia importa el día que haya que recuperar una contraseña:
 * si el correo estaba mal escrito, la cuenta queda verificada y a la vez
 * inalcanzable.
 *
 * Así que se guarda quién lo hizo:
 *
 *   NULO      la persona abrió el enlace de su correo. El correo funciona.
 *   CON VALOR un administrador lo dio por bueno. El correo NO está comprobado.
 *
 * Nulo por defecto, que es justo lo correcto para todo lo ya verificado: lo
 * fue por el enlace, porque hasta hoy no había otra forma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->unsignedBigInteger('email_verified_by')
                ->nullable()
                ->after('email_verified_at');

            /*
             * `nullOnDelete` y no `cascade`: si el administrador que lo
             * verificó se da de baja, la cuenta del vecino no se puede ir con
             * él. Se pierde el nombre y se conserva el hecho de que fue manual
             * —la columna sigue distinguiéndose de "nunca se tocó" por el
             * registro de auditoría—, que es lo importante.
             */
            $table->foreign('email_verified_by')
                ->references('user_id')->on('user')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->dropForeign(['email_verified_by']);
            $table->dropColumn('email_verified_by');
        });
    }
};
