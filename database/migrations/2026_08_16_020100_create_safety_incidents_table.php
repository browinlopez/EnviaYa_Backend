<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incidentes y accidentes durante la operación.
 *
 * Lo que hoy se cuenta por WhatsApp y no queda en ninguna parte. Sin registro
 * no hay forma de responder las dos preguntas que SST tiene que poder
 * responder: cuántos accidentes hubo este trimestre y si se repiten en la
 * misma zona, con el mismo vehículo o a la misma hora.
 *
 * `days_off` existe porque es el número que piden ARL y las estadísticas de
 * severidad: no basta con saber que hubo un accidente, hace falta cuánto
 * tiempo dejó a la persona sin poder trabajar.
 *
 * `domiciliary_id` y `order_id` son nulos porque un incidente puede no
 * involucrar a ninguno de los dos —una caída en la sede, un robo a un
 * mensajero que no estaba en pedido— y forzarlos dejaría casos sin registrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safety_incidents', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('domiciliary_id')->nullable();
            $table->unsignedInteger('order_id')->nullable();

            $table->timestamp('occurred_at');

            $table->enum('type', [
                'accidente_transito',
                'caida',
                'robo',
                'agresion',
                'falla_vehiculo',
                'condicion_insegura', // riesgo detectado que aún no causó daño
                'otro',
            ]);

            /*
             * Gravedad declarada por quien registra. Se separa de `days_off`
             * porque se conoce el mismo día, mientras que la incapacidad puede
             * tardar semanas en confirmarse: si dependieran una de otra, el
             * reporte quedaría sin gravedad hasta que llegara el parte médico.
             */
            $table->enum('severity', ['leve', 'moderado', 'grave'])->default('leve');

            $table->boolean('had_injuries')->default(false);
            $table->unsignedSmallInteger('days_off')->default(0);

            $table->string('location', 255)->nullable();
            $table->text('description');

            // Qué se hizo. Un incidente cerrado sin acciones es un incidente
            // que se olvidó, no uno que se resolvió.
            $table->text('actions')->nullable();

            // 0 abierto | 1 en investigación | 2 cerrado
            $table->tinyInteger('state')->default(0);
            $table->timestamp('closed_at')->nullable();

            $table->unsignedBigInteger('reported_by')->nullable();

            $table->timestamps();

            $table->index(['state', 'occurred_at'], 'incidente_estado_fecha_idx');
            $table->index('domiciliary_id');
            $table->index('severity');

            $table->foreign('domiciliary_id')
                ->references('domiciliary_id')->on('domiciliary')
                ->nullOnDelete();

            $table->foreign('order_id')
                ->references('orderSales_id')->on('orderssales')
                ->nullOnDelete();

            $table->foreign('reported_by')
                ->references('user_id')->on('user')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_incidents');
    }
};
