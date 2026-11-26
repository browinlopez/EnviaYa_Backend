<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOS TURNOS DE LA PORTERÍA Y EL AVISO DE QUIEN LLEVA MUCHO ADENTRO.
 *
 * `complex_shifts`: cuándo empezó y terminó cada turno de cada celador. Un
 * turno abierto es el que tiene `ended_at` en null, y la regla de uno abierto
 * por celador la sostiene el controlador dentro de una transacción con
 * bloqueo (un índice único parcial no existe en MySQL).
 *
 * `residential_complexes.horas_alerta_adentro`: a partir de cuántas horas
 * adentro una entrada sin salida se marca como demorada. Null es el valor de
 * la plataforma (4 h): así cambiar el valor por defecto no exige tocar cada
 * conjunto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complex_shifts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('complex_id');
            $t->foreign('complex_id')->references('complex_id')->on('residential_complexes')->cascadeOnDelete();
            $t->unsignedBigInteger('user_id');
            $t->foreign('user_id')->references('user_id')->on('user')->cascadeOnDelete();
            $t->timestamp('started_at');
            $t->timestamp('ended_at')->nullable();
            $t->timestamps();

            $t->index(['complex_id', 'ended_at'], 'turnos_conjunto_abiertos_idx');
            $t->index(['user_id', 'started_at'], 'turnos_celador_idx');
        });

        Schema::table('residential_complexes', function (Blueprint $t) {
            $t->unsignedTinyInteger('horas_alerta_adentro')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complex_shifts');

        Schema::table('residential_complexes', fn (Blueprint $t) => $t->dropColumn('horas_alerta_adentro'));
    }
};
