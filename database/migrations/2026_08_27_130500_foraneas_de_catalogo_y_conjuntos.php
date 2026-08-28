<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAS FORÁNEAS QUE FALTABAN EN EL CATÁLOGO Y EN LOS CONJUNTOS.
 *
 * Segunda tanda. La primera cerró el núcleo —pivote de catálogo, comprobantes,
 * dirección del pedido—; ésta cierra lo que quedó en tablas que sí se usan:
 *
 *   products.category_id                        587 filas
 *   category_category_business.category_id       14 filas
 *   category_category_business.business_category_id
 *   residential_complexes.municipality_id         9 filas
 *   catalog_uploads.busines_id / .user_id
 *
 * Todas con los datos ya coherentes: cero referencias rotas al comprobar.
 *
 * Tres de ellas necesitan igualar el tipo antes: la columna es `bigint
 * unsigned` y la clave a la que apunta es `int unsigned`. Es el mismo desajuste
 * que tenía `products_business.busines_id`, y viene de que las tablas viejas
 * usan `int` para sus claves mientras las nuevas usan el `bigint` que Laravel
 * pone por defecto. Los valores caben de sobra.
 *
 * LO QUE SE DEJA SIN FORÁNEA, A PROPÓSITO:
 *
 *  · `advertisers.tax_id` — es un NIT, no una clave ajena. El nombre engaña.
 *  · `media_files.entity_id`, `personal_access_tokens.tokenable_id` —
 *    polimórficas: apuntan a una tabla distinta según la fila.
 *  · `payments.provider_payment_id`, `payment_intents.bold_reference_id`,
 *    `payment_events.reference_id`, `payment_transactions.provider_transaction_id`
 *    — identificadores de Bold, de otro sistema.
 *  · `returns.*`, `resolutions.*`, `promotions.busines_id`,
 *    `status_history.order_id`, `user_reviews.domiciliary_id` — tablas vacías
 *    de módulos que se diseñaron y nunca se construyeron. Ponerles foráneas
 *    sería decorar algo que habría que decidir si se borra.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products MODIFY category_id INT UNSIGNED NULL');
            DB::statement('ALTER TABLE category_category_business MODIFY category_id INT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE catalog_uploads MODIFY busines_id INT UNSIGNED NOT NULL');
        }

        Schema::table('products', function (Blueprint $table) {
            /*
             * `nullOnDelete` y no restrict: si se retira una categoría, el
             * producto sigue existiendo — se queda sin clasificar, que es
             * molesto pero recuperable. Restringir obligaría a reclasificar
             * cientos de productos antes de poder tocar el catálogo de
             * categorías.
             */
            $table->foreign('category_id', 'fk_producto_categoria')
                ->references('category_id')->on('category')
                ->nullOnDelete();
        });

        Schema::table('category_category_business', function (Blueprint $table) {
            // Un pivote sin sus dos extremos no significa nada: cascade.
            $table->foreign('category_id', 'fk_ccb_categoria')
                ->references('category_id')->on('category')
                ->cascadeOnDelete();

            $table->foreign('business_category_id', 'fk_ccb_categoria_negocio')
                ->references('id')->on('category_business')
                ->cascadeOnDelete();
        });

        Schema::table('residential_complexes', function (Blueprint $table) {
            $table->foreign('municipality_id', 'fk_conjunto_municipio')
                ->references('id')->on('municipalities')
                ->nullOnDelete();
        });

        Schema::table('catalog_uploads', function (Blueprint $table) {
            // La carga se va con la tienda; de quién la subió sólo se pierde el
            // nombre, no el registro de que ocurrió.
            $table->foreign('busines_id', 'fk_carga_negocio')
                ->references('busines_id')->on('business')
                ->cascadeOnDelete();

            $table->foreign('user_id', 'fk_carga_usuario')
                ->references('user_id')->on('user')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_uploads', function (Blueprint $t) {
            $t->dropForeign('fk_carga_usuario');
            $t->dropForeign('fk_carga_negocio');
        });

        Schema::table('residential_complexes', fn (Blueprint $t) => $t->dropForeign('fk_conjunto_municipio'));

        Schema::table('category_category_business', function (Blueprint $t) {
            $t->dropForeign('fk_ccb_categoria_negocio');
            $t->dropForeign('fk_ccb_categoria');
        });

        Schema::table('products', fn (Blueprint $t) => $t->dropForeign('fk_producto_categoria'));

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE catalog_uploads MODIFY busines_id BIGINT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE category_category_business MODIFY category_id BIGINT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE products MODIFY category_id BIGINT UNSIGNED NULL');
        }
    }
};
