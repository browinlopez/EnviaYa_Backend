<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las entradas de domiciliarios a un conjunto.
 *
 * Sin esta tabla la portería no deja rastro, y entonces no sirve como control:
 * el celador comprobaría en pantalla que la persona tiene un pedido, la dejaría
 * pasar, y al día siguiente nadie podría decir quién entró ni cuándo.
 *
 * Se guarda también CÓMO se identificó —código o cédula— porque son dos niveles
 * de confianza distintos: el código lo genera la app del domiciliario y caduca
 * en cinco minutos; la cédula la escribe el celador y solo prueba que alguien
 * dijo un número.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complex_entries', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('complex_id');
            $table->foreign('complex_id')
                ->references('complex_id')->on('residential_complexes')
                ->cascadeOnDelete();

            // `domiciliary.domiciliary_id` es `int unsigned`.
            $table->unsignedInteger('domiciliary_id');
            $table->foreign('domiciliary_id')
                ->references('domiciliary_id')->on('domiciliary')
                ->cascadeOnDelete();

            // codigo | cedula
            $table->string('method', 20);

            /*
             * Cuántos pedidos justificaban la entrada, y cuáles.
             *
             * El número va aparte del detalle para poder responder "¿cuántas
             * entradas hubo sin pedido?" sin abrir un JSON en cada fila.
             */
            $table->unsignedSmallInteger('orders_count')->default(0);
            $table->json('orders')->nullable();

            $table->unsignedBigInteger('registered_by')->nullable();
            $table->foreign('registered_by')
                ->references('user_id')->on('user')->nullOnDelete();

            $table->timestamps();

            $table->index(['complex_id', 'created_at'], 'entradas_conjunto_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complex_entries');
    }
};
