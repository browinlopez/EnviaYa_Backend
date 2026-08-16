<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una categoría de producto puede pertenecer a VARIAS categorías de negocio.
 *
 * Hasta ahora la relación vivía en `category.business_category_id`, una sola
 * columna: "Bebidas" solo podía existir para tiendas, y había que duplicar la
 * categoría para ofrecerla también en restaurantes. Eso son dos taxonomías
 * distintas con el mismo nombre y productos repartidos entre ambas.
 *
 * La columna NO se elimina: se mantiene con la primera categoría de negocio
 * asignada, porque hay consultas del backend antiguo que la leen y quitarla
 * las rompería sin avisar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('category_category_business')) {
            Schema::create('category_category_business', function (Blueprint $table) {
                $table->id();
                // `category.category_id` es INT UNSIGNED y `category_business.id`
                // es BIGINT UNSIGNED: la clave foránea exige el mismo tipo exacto.
                $table->unsignedInteger('category_id');
                $table->unsignedBigInteger('business_category_id');

                $table->unique(['category_id', 'business_category_id'], 'cat_catbus_unico');
                $table->index('business_category_id');

                $table->foreign('category_id')
                    ->references('category_id')->on('category')
                    ->cascadeOnDelete();
                $table->foreign('business_category_id')
                    ->references('id')->on('category_business')
                    ->cascadeOnDelete();
            });
        }

        // Se arrastra lo que ya estaba en la columna para no perder ninguna
        // asignación existente.
        $existentes = DB::table('category')
            ->whereNotNull('business_category_id')
            ->get(['category_id', 'business_category_id']);

        foreach ($existentes as $c) {
            $valida = DB::table('category_business')->where('id', $c->business_category_id)->exists();
            if (!$valida) {
                continue;
            }

            DB::table('category_category_business')->insertOrIgnore([
                'category_id'          => $c->category_id,
                'business_category_id' => $c->business_category_id,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('category_category_business');
    }
};
