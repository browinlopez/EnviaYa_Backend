<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién paga la pauta.
 *
 * Un anunciante puede ser de dos naturalezas y por eso `business_id` es nulo:
 *
 *  · Una marca de afuera (una bebida, un banco) que no tiene nada que ver con
 *    el catálogo: solo compra espacio.
 *  · Un negocio que ya está en la plataforma y quiere promocionarse. Ahí se
 *    apunta a `business` para no volver a escribir su nombre ni su contacto, y
 *    para poder cruzar lo que paga en pauta contra lo que vende.
 *
 * Se guarda contacto propio igual: quien firma la pauta suele ser el área de
 * mercadeo de la marca, no la persona que atiende el mostrador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advertisers', function (Blueprint $table) {
            $table->id();

            $table->string('name', 150);

            // `business.busines_id` es INT UNSIGNED (increments), no BIGINT:
            // la foránea exige el mismo tipo exacto.
            $table->unsignedInteger('business_id')->nullable();

            $table->string('contact_name', 150)->nullable();
            $table->string('contact_email', 150)->nullable();
            $table->string('contact_phone', 30)->nullable();

            // Identificación tributaria de la marca, para facturarle la pauta.
            $table->string('tax_id', 40)->nullable();

            $table->text('notes')->nullable();
            $table->tinyInteger('state')->default(1); // 1 activo | 0 inactivo

            $table->timestamps();

            $table->index('state');
            $table->index('business_id');

            $table->foreign('business_id')
                ->references('busines_id')->on('business')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advertisers');
    }
};
