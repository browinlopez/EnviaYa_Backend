<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envíos masivos de notificaciones a un segmento de usuarios.
 *
 * El segmento se guarda como JSON y NO como una lista de destinatarios: los
 * criterios ("compradores de Barranquilla que pidieron alguna vez") describen
 * la intención y siguen siendo legibles meses después, mientras que una lista
 * de 4.000 identificadores no dice nada al releerla y queda obsoleta en
 * cuanto alguien se registra.
 *
 * `recipients_count` se llena al enviar, con el tamaño real del segmento en ese
 * momento. Es el único número honesto: recalcularlo hoy daría otro resultado.
 *
 * `sent_at` separa lo programado de lo enviado. Un envío sin `sent_at` todavía
 * se puede editar o cancelar; con `sent_at` ya salió y es historia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_campaigns', function (Blueprint $table) {
            $table->id();

            $table->string('title', 120);
            $table->string('body', 500);

            // A dónde lleva al tocarla, con la misma convención que `banners`.
            $table->enum('link_type', ['none', 'url', 'business', 'product', 'category'])
                ->default('none');
            $table->string('link_value', 500)->nullable();

            /*
             * Criterios del segmento. Claves admitidas:
             *   roles, municipalities, complexes, only_with_orders
             * Un objeto vacío significa "todos los usuarios activos".
             *
             * No hay filtro por antigüedad porque `user` no tiene `created_at`:
             * habría que añadir la columna antes de poder ofrecerlo.
             */
            $table->json('segment')->nullable();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->unsignedBigInteger('recipients_count')->default(0);

            // 0 borrador | 1 programada | 2 enviada | 3 cancelada
            $table->tinyInteger('state')->default(0);

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index(['state', 'scheduled_at'], 'push_agenda_idx');

            $table->foreign('created_by')
                ->references('user_id')->on('user')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_campaigns');
    }
};
