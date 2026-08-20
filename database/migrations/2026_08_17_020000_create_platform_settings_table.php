<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AJUSTES DE LA PLATAFORMA
 *
 * Las reglas de la operación —cuánto le queda al domiciliario, cuántas entregas
 * puede llevar a la vez, a las cuántas horas un pedido se considera atascado—
 * vivían solo en `config/services.php`, o sea en el `.env`. Cambiar la comisión
 * era editar un archivo en el servidor y reiniciar; la pantalla de Ajustes las
 * mostraba en solo lectura y lo decía.
 *
 * Eso convierte una decisión de gestión en una tarea de programación, y hace que
 * nadie sepa quién la cambió ni cuándo.
 *
 * Acá cada ajuste es una fila. Lo importante del diseño:
 *
 *  · SOLO lo que se cambia queda guardado. Sin fila, el valor es el que dice el
 *    catálogo (que a su vez sale de la configuración), así que el `.env` sigue
 *    siendo el valor por defecto y no hay que sembrar nada para arrancar.
 *
 *  · `value` es texto, no un tipo por columna. El catálogo dice de qué tipo es
 *    cada clave y se convierte al leer: una columna por tipo obligaría a migrar
 *    la tabla cada vez que se agregue un ajuste de una forma nueva.
 *
 *  · Queda quién y cuándo. Un cambio en la comisión mueve plata; sin autor, seis
 *    meses después nadie puede explicar por qué la liquidación de marzo no
 *    cuadra con la de abril.
 *
 *  · La clave primaria es un `id` numérico y `key` va con índice único, aunque la
 *    clave sea lo que identifica al ajuste. Es por la AUDITORÍA: el paquete
 *    guarda `auditable_id` como entero, así que con la clave de texto como
 *    primaria MySQL rechazaba el registro del cambio —"Incorrect integer
 *    value"—. SQLite lo aceptaba, con lo cual las pruebas pasaban y el fallo
 *    solo aparecía en la base de verdad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->text('value')->nullable();

            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // `nullOnDelete`: si se borra al usuario, el ajuste se queda —lo que
            // se perdería es el nombre de quien lo cambió, no el valor.
            $table->foreign('updated_by')
                ->references('user_id')->on('user')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
