<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LO QUE HACÍA FALTA PARA QUE UNA CANCELACIÓN DEJE RASTRO Y DEVUELVA LA PLATA.
 *
 * Dos huecos:
 *
 *   · `status_history` existe desde siempre, con 0 filas y sin nadie que la
 *     escriba. Le falta además el POR QUÉ: un pedido cancelado sin motivo no
 *     se puede responder cuando el cliente pregunta tres días después.
 *
 *   · Los pagos no tenían dónde apuntar una devolución. Cancelar un pedido ya
 *     cobrado devolvía el inventario y el cupo de fiado, y la plata se quedaba
 *     en Bold sin que nadie lo supiera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_history', function (Blueprint $table) {
            $table->text('motivo')->nullable()->after('status_history');
        });

        Schema::table('payments', function (Blueprint $table) {
            /*
             * `pendiente`   → hay que devolver y todavía no se hizo.
             * `manual`      → Bold no la puede hacer sola (QR y PSE): alguien
             *                 tiene que entrar al panel. Es el caso que más
             *                 fácil se pierde, por eso tiene nombre propio.
             * `anulada`     → se anuló el mismo día, antes de las 9 p. m.
             * `solicitada`  → reembolso pedido; Bold lo revisa.
             * `devuelta`    → confirmado por Bold.
             * `rechazada`   → Bold dijo que no.
             */
            $table->string('refund_status', 20)->nullable()->after('status');
            $table->text('refund_reason')->nullable()->after('refund_status');
            $table->timestamp('refunded_at')->nullable()->after('refund_reason');
            $table->json('refund_snapshot')->nullable()->after('refunded_at');

            /* Se consulta «¿qué devoluciones quedaron a medias?» y sin índice
               eso recorre la tabla entera de pagos. */
            $table->index('refund_status');
        });
    }

    public function down(): void
    {
        Schema::table('status_history', function (Blueprint $table) {
            $table->dropColumn('motivo');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['refund_status']);
            $table->dropColumn([
                'refund_status',
                'refund_reason',
                'refunded_at',
                'refund_snapshot',
            ]);
        });
    }
};
