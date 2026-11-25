<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DENUNCIAR A UN COMPRADOR DESDE EL CHAT.
 *
 * El tendero habla con sus clientes por el chat de la app, y hasta ahora no
 * tenía dónde contar que uno lo está extorsionando, amenazando o insultando.
 * Lo más parecido era el PQRS de Soporte, que no sabe de chats ni de con quién
 * se está hablando.
 *
 * POR QUÉ UNA TABLA PROPIA, habiendo dos donde esto casi cabe:
 *
 * · `safety_incidents` —donde aterriza el botón de SOS— es de seguridad
 *   LABORAL del domiciliario: `domiciliary_id`, `severity`, `had_injuries`,
 *   `days_off`, y su página del panel tiene columnas «Domiciliario ·
 *   Gravedad · Incapacidad». Un comprador que extorsiona por el chat no tiene
 *   gravedad ni días de incapacidad, y sobre todo NO HAY NINGUNA COLUMNA
 *   DONDE QUEPA A QUIÉN SE REPORTA. Meterlo ahí dejaría una página que miente.
 *
 * · `pqrs` trae estados, prioridad, responsable y plazos, y sería tentador.
 *   Pero tampoco tiene a quién se reporta ni referencia al chat, sus tipos son
 *   `reclamo/queja/peticion/sugerencia`, y mezclaría en la misma cola «mi
 *   pedido llegó tarde» con «este comprador me está extorsionando», metiendo
 *   mensajes de terceros en un buzón de atención general. Es una denuncia, no
 *   una petición.
 *
 * LA PRUEBA VIAJA DENTRO. `evidence` guarda una copia de los últimos mensajes
 * de la conversación, hecha por el servidor. Un reporte que sólo dice
 * «extorsión» obliga a quien lo revise a ir a buscar el chat, y los mensajes
 * se pueden borrar o perder por el camino.
 *
 * Los estados son los mismos tres de `safety_incidents` —abierto, revisando,
 * cerrado— para que el equipo no tenga que aprenderse dos vocabularios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_reports', function (Blueprint $tabla) {
            $tabla->id();

            $tabla->unsignedInteger('chat_id');
            $tabla->foreign('chat_id')->references('chat_id')->on('chats')->cascadeOnDelete();

            /* Quién denuncia y a quién. Las dos nulables al borrar la cuenta:
               el reporte sobrevive a la persona, igual que `reported_by` en
               `safety_incidents`, porque si no, borrarse la cuenta sería la
               forma más fácil de borrar la denuncia. */
            $tabla->unsignedBigInteger('reported_by')->nullable();
            $tabla->foreign('reported_by')->references('user_id')->on('user')->nullOnDelete();

            $tabla->unsignedBigInteger('reported_user_id')->nullable();
            $tabla->foreign('reported_user_id')->references('user_id')->on('user')->nullOnDelete();

            $tabla->unsignedInteger('business_id')->nullable();
            $tabla->foreign('business_id')->references('busines_id')->on('business')->nullOnDelete();

            $tabla->enum('reason', [
                'extorsion',
                'amenazas',
                'mensajes_ofensivos',
                'estafa',
                'suplantacion',
                'otro',
            ]);

            $tabla->text('description')->nullable();
            $tabla->json('evidence')->nullable();

            $tabla->tinyInteger('state')->default(0);
            $tabla->unsignedBigInteger('reviewed_by')->nullable();
            $tabla->foreign('reviewed_by')->references('user_id')->on('user')->nullOnDelete();
            $tabla->timestamp('closed_at')->nullable();

            $tabla->timestamps();

            /* Lo que se va a preguntar es «¿este comprador ya tenía
               reportes?» y «¿qué hay sin revisar?». */
            $tabla->index('reported_user_id');
            $tabla->index('business_id');
            $tabla->index(['state', 'created_at'], 'reporte_estado_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_reports');
    }
};
