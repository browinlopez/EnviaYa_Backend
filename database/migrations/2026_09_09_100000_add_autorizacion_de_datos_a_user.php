<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CUÁNDO AUTORIZÓ CADA QUIEN EL TRATAMIENTO DE SUS DATOS
 *
 * La Ley 1581 pide autorización PREVIA, EXPRESA E INFORMADA para tratar datos
 * personales, y pide poder demostrarla. El registro de la app no pedía nada:
 * recogía nombre, apellidos, teléfono, correo, dirección y ubicación, y lo
 * ataba al historial de compras, sin una casilla ni un enlace a la política.
 *
 * Lo llamativo es que el formulario de la WEB sí la exige —no guarda nada sin
 * el consentimiento marcado, y anota la hora— mientras el registro de la
 * aplicación, que recoge mucho más, no preguntaba. Esto lo iguala.
 *
 * SE GUARDA LA VERSIÓN DE LA POLÍTICA, no solo un «sí». Una autorización vale
 * para lo que decía el texto cuando se dio; si mañana cambia la política, hay
 * que poder distinguir quién acepto cuál. Es lo mismo que ya hace
 * `landing_requests`.
 *
 * NULO PARA LAS CUENTAS QUE YA EXISTEN, a propósito. No se puede inventar una
 * autorización que nadie dio: un valor por defecto convertiría un hueco legal
 * en un dato falso, que es peor. Quedan marcadas como pendientes y se les
 * puede pedir cuando se decida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->timestamp('policy_accepted_at')->nullable();
            $table->string('policy_version', 20)->nullable();
            // 45 caracteres: lo que ocupa una IPv6 escrita entera.
            $table->string('policy_ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->dropColumn(['policy_accepted_at', 'policy_version', 'policy_ip']);
        });
    }
};
