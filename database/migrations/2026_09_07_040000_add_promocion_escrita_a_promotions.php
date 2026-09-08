<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LA PROMOCIÓN QUE ESCRIBE EL TENDERO
 *
 * `promotions` existe desde 2025 con `code_promotions` y `percentage_discount`:
 * la forma de un motor de descuentos. Nunca se llenó —cero filas, y nada en el
 * código la crea—, así que se reutiliza en vez de levantar una segunda tabla
 * que se llamaría casi igual. Dos tablas de promociones, una viva y otra
 * muerta, es la clase de cosa que hace dudar seis meses después.
 *
 * QUÉ ES Y QUÉ NO ES. Esto es un AVISO ESCRITO, no un descuento que se aplica
 * solo. El tendero escribe «Compra 2 atunes y el tercero va gratis», elige a
 * qué productos se refiere, y a sus clientes afiliados les llega esa frase. El
 * descuento lo hace él en el mostrador, como ya lo hace hoy.
 *
 * Se decidió así porque es lo que se pidió y porque un motor que aplique «el
 * tercero gratis» solo tendría que meterse en el cálculo del pedido, donde las
 * seis cifras se congelan al crear y no se vuelven a tocar. Es un trabajo
 * distinto y mucho mayor, y hacerlo a medias sería peor que no hacerlo: una
 * promoción que a veces se aplica y a veces no.
 *
 * `percentage_discount` se deja donde está, sin usar. El día que se quiera
 * aplicar de verdad, la promoción ya está atada a sus productos y ya existe
 * `orders_promotions` para dejar constancia en el pedido.
 *
 * POR QUÉ `sent_at` Y `recipients_count`. Una promoción no se puede desenviar.
 * `sent_at` marca el momento en que dejó de ser editable, y el contador guarda
 * a cuánta gente llegó ENTONCES: recalcularlo hoy daría otro número, porque los
 * afiliados cambian. Es el mismo criterio que ya usa `push_campaigns`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            if (!Schema::hasColumn('promotions', 'sent_at')) {
                $table->timestamp('sent_at')->nullable()->after('end_date');
            }

            if (!Schema::hasColumn('promotions', 'recipients_count')) {
                $table->unsignedInteger('recipients_count')->default(0)->after('sent_at');
            }

            if (!Schema::hasColumn('promotions', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('recipients_count');
            }

            if (!Schema::hasColumn('promotions', 'created_at')) {
                $table->timestamps();
            }
        });

        /*
         * A QUÉ PRODUCTOS SE REFIERE.
         *
         * Tabla aparte y no una lista en JSON: así el cliente que recibe el
         * aviso puede tocar el producto y llegar a él, y el día que alguien
         * pregunte «¿qué promociones ha tenido este atún?» hay una consulta que
         * responderla. Un JSON obligaría a leerlas todas y abrirlas una a una.
         *
         * Puede quedar vacía: «20% en toda la tienda hoy» no es de ningún
         * producto en particular, y obligar a elegir uno sería obligar a mentir.
         */
        if (!Schema::hasTable('promotion_products')) {
            Schema::create('promotion_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('promotion_id');
                $table->unsignedBigInteger('products_id');

                $table->foreign('promotion_id')
                    ->references('promotion_id')->on('promotions')
                    ->onDelete('cascade');

                // El mismo producto dos veces en la misma promoción no significa
                // nada, y en la lista del aviso saldría repetido.
                $table->unique(['promotion_id', 'products_id'], 'promo_producto_unico');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_products');

        Schema::table('promotions', function (Blueprint $table) {
            foreach (['sent_at', 'recipients_count', 'created_by'] as $columna) {
                if (Schema::hasColumn('promotions', $columna)) {
                    $table->dropColumn($columna);
                }
            }

            if (Schema::hasColumn('promotions', 'created_at')) {
                $table->dropTimestamps();
            }
        });
    }
};
