<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Negocios que pagan por aparecer arriba.
 *
 * Va en su propia tabla y no como una columna `destacado` en `business` por
 * tres razones que la columna no puede cubrir: el destaque tiene VIGENCIA (se
 * paga por un mes), tiene PRECIO (hay que poder reportar cuánto entró por este
 * concepto) y un mismo negocio puede estar destacado en dos sitios distintos a
 * la vez con prioridades distintas. Una columna booleana perdería las tres.
 *
 * `paid_amount` es lo cobrado por ese periodo, no una tarifa: si se renegocia,
 * el registro viejo debe conservar lo que efectivamente se facturó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('featured_businesses', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('business_id');

            /*
             * Dónde se destaca. Se repite la idea de `banners.placement` pero
             * con valores propios: acá no se pinta una imagen suelta sino la
             * ficha del negocio dentro de un listado que ya existe.
             *
             *  home_top      — primeros puestos del inicio
             *  category_top  — primeros puestos dentro de su categoría
             *  search_top    — primeros resultados de búsqueda
             */
            $table->enum('placement', ['home_top', 'category_top', 'search_top'])
                ->default('home_top');

            $table->unsignedInteger('priority')->default(0);

            $table->date('starts_at');
            $table->date('ends_at');

            $table->decimal('paid_amount', 12, 2)->default(0);

            // Métrica mínima para justificar la renovación.
            $table->unsignedBigInteger('impressions_count')->default(0);
            $table->unsignedBigInteger('clicks_count')->default(0);

            $table->tinyInteger('state')->default(1); // 1 activo | 0 pausado

            $table->timestamps();

            $table->index(['state', 'placement', 'starts_at', 'ends_at'], 'destacado_vigencia_idx');
            $table->index('business_id');

            $table->foreign('business_id')
                ->references('busines_id')->on('business')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('featured_businesses');
    }
};
