<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LO QUE EL ADMINISTRADOR PIDE CAMBIAR Y NO PUEDE CAMBIAR SOLO.
 *
 * Las torres y los apartamentos por torre los fija la plataforma: de ahí sale
 * el denominador con que se mide el edificio, y que el propio conjunto lo
 * moviera volvería el dato declarado. El administrador lo PIDE: se radica un
 * PQRS (tipo petición) con lo que hay y lo que quiere, y aquí queda la
 * solicitud para que su pantalla diga «enviada el …, en revisión» mientras el
 * caso siga abierto. El conjunto no cambia hasta que alguien del equipo lo
 * cambie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complex_change_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('complex_id');
            $t->foreign('complex_id')->references('complex_id')->on('residential_complexes')->cascadeOnDelete();
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->foreign('requested_by')->references('user_id')->on('user')->nullOnDelete();
            // Lo que había al pedirlo y lo que se pide: el caso se lee solo.
            $t->unsignedSmallInteger('towers_before')->nullable();
            $t->unsignedSmallInteger('apartments_before')->nullable();
            $t->unsignedSmallInteger('towers_count');
            $t->unsignedSmallInteger('apartments_per_tower');
            $t->text('note')->nullable();
            $t->unsignedBigInteger('pqrs_id')->nullable();
            $t->foreign('pqrs_id')->references('id')->on('pqrs')->nullOnDelete();
            $t->timestamps();

            $t->index(['complex_id', 'created_at'], 'cambios_conjunto_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complex_change_requests');
    }
};
