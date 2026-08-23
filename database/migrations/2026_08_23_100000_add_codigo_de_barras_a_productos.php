<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El código de barras, que es lo que hace que dos tiendas hablen del mismo
 * producto.
 *
 * `products` es un catálogo compartido: hoy hay 145 productos que se venden en
 * más de un negocio, y esa es la razón de que la plataforma pueda enseñar las
 * cinco tiendas que tienen Coca-Cola cuando alguien la busca.
 *
 * Pero que sean el mismo producto depende, hasta ahora, de que quien cargó el
 * Excel lo escribiera igual que la vez pasada. Con siete negocios y una sola
 * persona cargando se sostiene; con doscientos aparecen «COCA COLA 400»,
 * «Coca-Cola 400ml» y «COCACOLA 400 ML» como tres filas distintas, y ahí se
 * acabó el catálogo compartido: vuelve a ser un catálogo por tienda.
 *
 * El código de barras convierte «es el mismo producto» en algo comprobable con
 * la cámara en vez de en una cuestión de ortografía.
 *
 * NULLABLE porque los 587 productos que ya existen no lo tienen, y porque hay
 * cosas que legítimamente no llevan: un almuerzo de un restaurante, algo a
 * granel. ÚNICO porque no tenerlo único sería no tener nada — la columna existe
 * exactamente para garantizar eso. En MySQL varios NULL conviven sin violar el
 * único, que es justo lo que hace falta acá.
 *
 * `origen` va al lado a propósito. Cuando un tendero proponga un producto que
 * no está en el catálogo, esa fila la van a ver todas las demás tiendas: hay
 * que poder distinguir después lo que entró revisado por el equipo de lo que
 * entró desde un local. Marcarlo permite auditar sin frenar a nadie, que es
 * mejor que una cola de aprobación que nadie atiende.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('barcode', 20)->nullable()->unique()->after('name');

            $table->string('brand', 120)->nullable()->after('description');

            /*
             * De dónde salió la fila: 'equipo' lo que cargó la administración,
             * 'tendero' lo que propuso un local, 'externo' lo que se trajo de
             * una fuente pública al escanear un código desconocido.
             */
            $table->string('origen', 20)->default('equipo')->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['barcode']);
            $table->dropColumn(['barcode', 'brand', 'origen']);
        });
    }
};
