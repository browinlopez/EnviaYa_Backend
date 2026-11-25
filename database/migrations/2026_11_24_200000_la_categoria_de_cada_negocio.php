<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LA CATEGORÍA DE UN PRODUCTO DEPENDE DE QUIÉN LO VENDE.
 *
 * La categoría vivía SOLO en `products`, el catálogo compartido. Pero el Vick
 * VapoRub lo venden la droguería y la tienda de la esquina, y las categorías
 * son de cada oficio: en la droguería es «Gripa y tos»; en la tienda esa
 * categoría no existe, así que la tienda acababa enseñando «Medicina», una
 * categoría de droguería, en su menú.
 *
 *  · `products_business.category_id`: la categoría de ESTE negocio para ESTE
 *    producto. Opcional: sin ella manda la del producto, como hasta ahora,
 *    así que nada cambia para quien no la use.
 *
 *  · Y a la tienda le faltaban categorías para lo que una tienda de barrio
 *    sí vende: el shampoo, las toallas higiénicas, el Dolex de la noche, el
 *    pegante. Se crean las tres.
 */
return new class extends Migration
{
    private const DE_TIENDA = [
        'Cuidado personal e higiene' => 'Crema dental, desodorante, shampoo, toallas higiénicas, pañales.',
        'Botiquín y medicamentos' => 'Analgésicos, antigripales, sal de frutas, alcohol, curas.',
        'Ferretería y hogar' => 'Pegante, silicona, aceite lubricante, bombillos, pilas.',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('products_business', 'category_id')) {
            Schema::table('products_business', function (Blueprint $t) {
                $t->unsignedInteger('category_id')->nullable()->after('products_id');
                $t->index('category_id', 'products_business_category_index');
            });
        }

        /* En una base recién creada (las pruebas) el oficio Tienda aún no
           existe: sus categorías las pone el sembrado, no esto. */
        if (! DB::table('category_business')->where('id', 1)->exists()) {
            return;
        }

        foreach (self::DE_TIENDA as $nombre => $descripcion) {
            $existe = DB::table('category')
                ->where('business_category_id', 1)
                ->where('name', $nombre)
                ->exists();
            if (! $existe) {
                DB::table('category')->insert([
                    'name' => $nombre,
                    'description' => $descripcion,
                    'state' => 1,
                    'business_category_id' => 1,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products_business', 'category_id')) {
            Schema::table('products_business', function (Blueprint $t) {
                $t->dropIndex('products_business_category_index');
                $t->dropColumn('category_id');
            });
        }

        DB::table('category')
            ->where('business_category_id', 1)
            ->whereIn('name', array_keys(self::DE_TIENDA))
            ->delete();
    }
};
