<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada impresión y cada clic, con su fecha.
 *
 * Los contadores de `banners` responden "cuántos"; esta tabla responde
 * "cuándo" y "quién", que es lo que permite dibujar la curva por día y
 * defender el CTR ante el anunciante. Sin el detalle, un contador que sube
 * solo puede creerse o no.
 *
 * `user_id` es nulo a propósito: en la web y en la app sin sesión hay
 * impresiones perfectamente válidas, y exigir usuario dejaría fuera justo al
 * público al que se le quiere hacer publicidad.
 *
 * Se guarda `day` aparte de `created_at` porque toda consulta de este módulo
 * agrupa por día, y agrupar por DATE(created_at) no puede usar el índice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banner_events', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('banner_id');

            $table->enum('type', ['impression', 'click']);

            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('platform', 10)->nullable(); // app | web
            $table->string('ip', 45)->nullable();

            $table->date('day');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['banner_id', 'type', 'day'], 'evento_banner_dia_idx');
            $table->index('day');

            $table->foreign('banner_id')
                ->references('id')->on('banners')
                ->cascadeOnDelete();

            $table->foreign('user_id')
                ->references('user_id')->on('user')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banner_events');
    }
};
