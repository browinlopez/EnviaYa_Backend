<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Peticiones, quejas, reclamos y sugerencias.
 *
 * Calidad tenía reseñas y conversaciones, que son señales sueltas: una queja
 * se atendía si alguien la veía pasar por el chat. Esto le da radicado,
 * responsable y plazo, que es lo que convierte "alguien se quejó" en algo que
 * se puede seguir y cerrar.
 *
 * `code` es el radicado que se le da al cliente. Se genera en el servidor y es
 * único: es el número por el que va a preguntar cuando llame.
 *
 * `due_at` se calcula al radicar según la prioridad y NO se recalcula después.
 * Si se recalculara al cambiar la prioridad, subirle la urgencia a un caso
 * atrasado lo haría aparecer como si estuviera a tiempo, que es justo lo que
 * el indicador debe impedir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pqrs', function (Blueprint $table) {
            $table->id();

            $table->string('code', 20)->unique();

            $table->enum('type', [
                'peticion',
                'queja',
                'reclamo',
                'sugerencia',
                'felicitacion',
            ]);

            // Por dónde llegó. Sirve para saber qué canal genera más carga y
            // cuál se está quedando sin atender.
            $table->enum('channel', ['app', 'whatsapp', 'llamada', 'correo', 'panel'])
                ->default('app');

            $table->enum('priority', ['baja', 'media', 'alta'])->default('media');

            // A qué se refiere. Todos nulos: una sugerencia general no apunta a
            // ningún pedido ni negocio en particular.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedInteger('order_id')->nullable();
            $table->unsignedInteger('business_id')->nullable();
            $table->unsignedInteger('domiciliary_id')->nullable();

            // Datos de contacto para quien radica sin tener cuenta.
            $table->string('contact_name', 150)->nullable();
            $table->string('contact_email', 150)->nullable();
            $table->string('contact_phone', 30)->nullable();

            $table->string('subject', 200);
            $table->text('description');

            // 0 abierto | 1 en gestión | 2 resuelto | 3 cerrado
            $table->tinyInteger('state')->default(0);

            $table->unsignedBigInteger('assigned_to')->nullable();

            $table->timestamp('due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution')->nullable();

            $table->timestamps();

            // Así se consulta el tablero: lo abierto, ordenado por plazo.
            $table->index(['state', 'due_at'], 'pqrs_estado_plazo_idx');
            $table->index(['type', 'created_at'], 'pqrs_tipo_fecha_idx');
            $table->index('assigned_to');

            $table->foreign('user_id')->references('user_id')->on('user')->nullOnDelete();
            $table->foreign('order_id')->references('orderSales_id')->on('orderssales')->nullOnDelete();
            $table->foreign('business_id')->references('busines_id')->on('business')->nullOnDelete();
            $table->foreign('domiciliary_id')->references('domiciliary_id')->on('domiciliary')->nullOnDelete();
            $table->foreign('assigned_to')->references('user_id')->on('user')->nullOnDelete();
        });

        /**
         * Cada movimiento del caso.
         *
         * Sin la bitácora, un PQRS resuelto solo muestra el texto final y nadie
         * puede saber qué se intentó, cuándo ni quién lo hizo. Eso es
         * exactamente lo que se necesita cuando el cliente reclama que nadie lo
         * atendió.
         */
        Schema::create('pqrs_notes', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('pqrs_id');
            $table->unsignedBigInteger('user_id')->nullable();

            $table->text('note');

            // Una nota interna no se le muestra al cliente: permite dejar
            // constancia de algo sin exponerlo.
            $table->boolean('is_internal')->default(false);

            $table->timestamp('created_at')->useCurrent();

            $table->index('pqrs_id');

            $table->foreign('pqrs_id')->references('id')->on('pqrs')->cascadeOnDelete();
            $table->foreign('user_id')->references('user_id')->on('user')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pqrs_notes');
        Schema::dropIfExists('pqrs');
    }
};
