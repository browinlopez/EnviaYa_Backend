<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EL PRODUCTO COMO LO PIENSA QUIEN COMPRA.
 *
 * Hoy el catálogo tiene ocho filas de «VELON BLANCO SAN JORGE», una por
 * número; siete de «SALSA DE TOMATE SAN JORGE», una por gramaje; y cinco de
 * «MR TEA», entre sabores y tamaños. Son productos distintos —se piden por
 * separado y cada uno tiene su código de barras— pero nadie los piensa así:
 * se piensa «velón San Jorge» y después se elige el número.
 *
 * La consecuencia se ve en las dos puntas. El comprador abre la tienda y
 * recorre ocho tarjetas casi idénticas para encontrar la que quería. El
 * tendero teclea ocho precios en ocho pantallas. Y la búsqueda devuelve ocho
 * resultados donde hay una sola cosa que decidir.
 *
 * EN EL CATÁLOGO ACTUAL ESTO SON 291 PRODUCTOS EN 115 GRUPOS, el 19% de las
 * 1.529 filas. No es un caso de borde: es uno de cada cinco productos.
 *
 * LO QUE **NO** CAMBIA, Y ES EL PUNTO DE ESTE DISEÑO
 *
 * `products_business` y `orderssales_detail` siguen apuntando a `products_id`.
 * No se toca una línea de pedidos, pagos, existencias ni informes. El grupo es
 * una capa de LECTURA que se pone encima; la escritura sigue igual de
 * aburrida que ayer, que es exactamente lo que se quiere de la tabla donde
 * vive el historial de ventas.
 *
 * La alternativa —hacer del grupo el producto canónico y mover
 * `products_business` a una tabla de variantes— obliga a migrar el historial
 * de pedidos, y un error ahí se descubre cuando no cuadra un informe de
 * ingresos. No hace falta: todo el beneficio está en la lectura.
 *
 * POR QUÉ EL CÓDIGO DE BARRAS YA DECÍA QUE ESTO ERA ASÍ
 *
 * `products.barcode` es único y la migración de agosto lo puso para que dos
 * tiendas hablen del mismo producto. Pero un EAN identifica UNA PRESENTACIÓN:
 * la Coca-Cola de 1,5 L y la de 250 ml tienen códigos distintos porque son
 * artículos distintos. O sea que `products` ya tenía forma de variante desde
 * entonces; lo que faltaba era la fila de arriba. Esta migración no cambia el
 * modelo, lo completa.
 *
 * `group_id` ES NULLABLE Y LA MAYORÍA LO VA A TENER EN NULL. Un producto que
 * se vende en una sola presentación no necesita grupo, y fabricarle uno de un
 * solo miembro sería ruido: la app enseña la tarjeta tal cual cuando no hay
 * nada que elegir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_groups', function (Blueprint $table) {
            $table->id('group_id');

            /*
             * El nombre del concepto, sin presentación: «Salsa de tomate San
             * Jorge». Es el título de la tarjeta, y debajo van los chips.
             */
            $table->string('name');

            $table->string('brand', 120)->nullable();

            /*
             * `category.category_id` es INT UNSIGNED. La clave foránea exige
             * el mismo tipo exacto, igual que en `category_category_business`.
             */
            $table->unsignedInteger('category_id')->nullable();

            /*
             * La foto que representa al grupo. Normalmente es la de una de sus
             * variantes —se parecen entre sí— y por eso puede quedar en null:
             * la app cae a la foto de la variante elegida.
             */
            $table->text('image')->nullable();
            $table->text('description')->nullable();

            /*
             * De dónde salió el grupo, con el mismo criterio que
             * `products.origen`: 'automatico' lo que sembró el agrupador por
             * parecido de nombre, 'equipo' lo que alguien revisó o corrigió a
             * mano. Sin esto no hay forma de saber qué agrupaciones están
             * comprobadas y cuáles son una apuesta del algoritmo.
             */
            $table->string('origen', 20)->default('automatico');

            $table->timestamps();

            $table->index('category_id');
            $table->foreign('category_id')
                ->references('category_id')->on('category')
                ->nullOnDelete();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('group_id')->nullable()->after('products_id');

            /*
             * LA ETIQUETA DEL CHIP: «1,5 L», «Durazno 500 ml», «# 10»,
             * «Etapa 3». Es lo único que distingue a esta variante de sus
             * hermanas, y va en su propia columna en vez de deducirse del
             * nombre cada vez que se pinta una lista.
             *
             * Se guarda en vez de calcularse porque el nombre viene del punto
             * de venta de cada tienda y está escrito de siete maneras —«*1 LT»,
             * «1.5», «1LT»—; normalizarlo en cada petición es trabajo repetido
             * y, lo que es peor, el resultado cambiaría si alguien corrige el
             * normalizador. Acá además se puede corregir a mano cuando el
             * algoritmo se equivoque.
             */
            $table->string('variante', 120)->nullable()->after('barcode');

            $table->index('group_id');
            $table->foreign('group_id')
                ->references('group_id')->on('product_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['group_id']);
            $table->dropIndex(['group_id']);
            $table->dropColumn(['group_id', 'variante']);
        });

        Schema::dropIfExists('product_groups');
    }
};
