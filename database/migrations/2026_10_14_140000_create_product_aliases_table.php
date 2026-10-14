<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CÓMO LLAMA CADA TIENDA A UN PRODUCTO DEL CATÁLOGO.
 *
 * EL PROBLEMA, VISTO EN PRODUCCIÓN. Al subir el inventario de Mercadito Búho
 * —1.010 filas— el importador creó 14 productos. Dos eran de verdad nuevos.
 * Los otros doce eran duplicados de productos que ya existían, y se colaron
 * por una razón que no tiene arreglo dentro del importador: él compara el
 * nombre exacto, y el nombre que la tienda le da a un producto NO es el que
 * tiene en el catálogo.
 *
 *     el archivo dice          el catálogo tiene
 *     ---------------          -----------------
 *     ACETAMINOFEN             ACETAMINOFEN TABLETAS 500MG XUNIDAD
 *     DON SABOR                DON SABOR EN SOBRE
 *     NORAVER GARGANTA         NORAVER-P GARGANTA
 *     MOSTAZA 8 GR             MOSTAZA SAN JORGE ADEREZO *8G
 *
 * Que esos pares sean el mismo producto es una decisión que alguien tomó
 * mirándolos. El importador no puede deducirla —y si lo intentara por
 * parecido, juntaría «ÁGUILA NEGRA» con «ÁGUILA», que son dos cervezas—. Lo
 * que faltaba no era un algoritmo mejor: era un sitio donde guardar la
 * decisión.
 *
 * SIN ESTO, CADA CARGA REPITE EL DESTROZO. El tendero sube su archivo cada vez
 * que le cambian los precios, y cada vez el catálogo compartido de todas las
 * tiendas gana doce productos duplicados.
 *
 * EL CÓDIGO DE BARRAS YA HACE ESTO CUANDO EXISTE: es el alias perfecto, porque
 * es el mismo número en todas partes. Esta tabla es para lo que no lo tiene —
 * y en el inventario real eso es el 42% de las filas.
 *
 * `busines_id` = 0 significa «todas»: un alias puede valer para cualquier
 * tienda («ACETAMINOFEN» es el mismo acetaminofén en todas) o solo para una,
 * cuando el nombre es una manía de ese local. Se empieza por el general, que
 * es el caso común, y el específico queda disponible sin otra migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_aliases', function (Blueprint $table) {
            $table->id('alias_id');

            $table->unsignedBigInteger('products_id');

            /*
             * El nombre YA NORMALIZADO: sin tildes, en mayúsculas y con los
             * espacios colapsados. Se guarda así, y no como lo escribió la
             * tienda, porque si no habría que normalizar las dos puntas en
             * cada consulta y el índice no serviría de nada.
             *
             * Eso además arregla sin querer tres de los doce duplicados, que
             * eran el mismo nombre con un espacio de más: «AJINOMEN CARNE  80
             * GR» contra «AJINOMEN CARNE 80 GR».
             */
            $table->string('alias', 255);

            /*
             * 0 = «vale para todas las tiendas». Un 0 y no un NULL porque en
             * SQL dos NULL se consideran distintos: con NULL, el único de
             * abajo NO impediría dos aliases generales iguales, que es
             * justamente lo que hay que impedir —con dos, la consulta elige a
             * ciegas entre dos productos distintos.
             *
             * El precio es que esta columna no lleva clave foránea: 0 no es
             * ningún negocio. Se acepta a cambio de que el único sirva.
             */
            $table->unsignedInteger('busines_id')->default(0);

            /*
             * De dónde salió: 'equipo' lo que revisó una persona, 'carga' lo
             * que aprendió el importador al casar un nombre, 'migracion' lo
             * que se sembró al crear la tabla. Mismo criterio que
             * `products.origen`: poder auditar después sin frenar a nadie.
             */
            $table->string('origen', 20)->default('equipo');

            $table->timestamps();

            /*
             * Un alias apunta a UN producto dentro de su ámbito. Sin el único,
             * dos cargas simultáneas crearían dos filas y la siguiente
             * consulta tendría que elegir entre ellas a ciegas.
             */
            $table->unique(['alias', 'busines_id'], 'uq_alias_por_negocio');
            $table->index('products_id');

            $table->foreign('products_id')
                ->references('products_id')->on('products')
                ->cascadeOnDelete();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_aliases');
    }
};
