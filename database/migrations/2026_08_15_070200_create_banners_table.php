<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La pieza publicitaria que ve el usuario.
 *
 * La imagen NO se guarda acá: va a `media_files` con `entity_type = 'banners'`,
 * igual que las de negocios y productos. Guardar una URL en columna fue el
 * error que ya se corrigió una vez (ver la migración de media_files): las URL
 * firmadas de R2 miden ~600 caracteres y caducan.
 *
 * SEGMENTACIÓN
 * Los cuatro `target_*` son JSON y se filtran en PHP, no en SQL. Es
 * deliberado: JSON_CONTAINS no existe en SQLite y la suite de pruebas corre
 * sobre SQLite en memoria, así que una consulta con JSON_CONTAINS sería
 * imposible de probar. Además el conjunto de banners vigentes es de decenas,
 * no de millones: traerlos y filtrarlos en memoria es más simple y más rápido
 * que un índice funcional sobre JSON. Un arreglo vacío significa "sin
 * restricción", que es lo que se quiere por defecto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('campaign_id');

            $table->string('title', 150);
            $table->string('subtitle', 255)->nullable();

            /*
             * Dónde se pinta. La app y la web piden por `placement`, así que
             * este valor es un contrato con los clientes: agregar uno nuevo
             * exige que el cliente sepa dibujarlo.
             *
             *  home_hero      — carrusel grande del inicio
             *  home_strip     — franja horizontal bajo las categorías
             *  listing_inline — tarjeta intercalada en el listado de negocios
             *  splash         — pantalla de entrada de la app
             *  web_home       — cabecera del sitio web
             */
            $table->enum('placement', [
                'home_hero',
                'home_strip',
                'listing_inline',
                'splash',
                'web_home',
            ])->default('home_hero');

            // Dónde aplica la pieza. `both` evita duplicar el mismo banner.
            $table->enum('platform', ['app', 'web', 'both'])->default('both');

            /*
             * Qué pasa al tocarlo. Se separa el tipo del valor para que el
             * cliente no tenga que adivinar si "123" es un negocio o una URL:
             * con `link_type = business` navega dentro de la app, y con `url`
             * abre el navegador.
             */
            $table->enum('link_type', ['none', 'url', 'business', 'product', 'category'])
                ->default('none');
            $table->string('link_value', 500)->nullable();

            // Mayor gana. Con empate decide el más reciente, para que subir una
            // pieza nueva no exija recalcular las prioridades de las demás.
            $table->unsignedInteger('priority')->default(0);

            // Vigencia propia, opcional: si va nula hereda la de la campaña.
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();

            // Segmentación. Vacío = sin restricción. Ver nota de arriba.
            $table->json('target_municipalities')->nullable();
            $table->json('target_complexes')->nullable();
            $table->json('target_business_categories')->nullable();
            $table->json('target_roles')->nullable();

            /*
             * Contadores desnormalizados. `banner_events` guarda el detalle
             * para poder auditar y hacer series por día; estos dos existen
             * para que el listado del panel no tenga que agregar millones de
             * filas en cada carga.
             */
            $table->unsignedBigInteger('impressions_count')->default(0);
            $table->unsignedBigInteger('clicks_count')->default(0);

            $table->tinyInteger('state')->default(1); // 1 activo | 0 pausado

            $table->timestamps();

            $table->index(['state', 'placement', 'platform'], 'banner_entrega_idx');
            $table->index(['priority', 'id'], 'banner_orden_idx');
            $table->index('campaign_id');

            $table->foreign('campaign_id')
                ->references('id')->on('ad_campaigns')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
