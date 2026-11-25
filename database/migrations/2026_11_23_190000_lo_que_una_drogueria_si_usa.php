<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ==========================================================================
 * LO QUE UNA DROGUERÍA SÍ USA Y NO TENÍA DÓNDE GUARDARSE.
 *
 * `pharmacy_products` existe desde el principio y está VACÍA: ni una fila,
 * con casi seiscientos productos reales en el catálogo. La razón es que sus
 * cuatro columnas —principio activo, dosis, presentación y una fecha de
 * vencimiento ya deprecada— no son las que una droguería de barrio necesita
 * para vender.
 *
 * Las tres que sí:
 *
 *   · CONTENIDO    «10 tabletas», «500 ml», «30 cápsulas». No es la dosis:
 *                  la dosis de un acetaminofén es 500 mg y el contenido es
 *                  cuántas trae la caja. Hoy no hay dónde ponerlo, y meterlo
 *                  en `dosage` hace que la pantalla diga «Dosis: 10
 *                  tabletas», que es falso.
 *   · INVIMA       el registro sanitario. Es lo que pide quien inspecciona,
 *                  y sin columna acaba escrito dentro del nombre.
 *   · FÓRMULA      si el medicamento la exige.
 *
 * --------------------------------------------------------------------------
 * POR QUÉ AQUÍ Y NO EN `products_business`.
 *
 * Los tres son del producto COMPARTIDO, no del par tienda-producto: el mismo
 * frasco trae el mismo contenido, el mismo registro y la misma exigencia de
 * fórmula en toda droguería del país. Lo que cambia por tienda es el precio,
 * la cantidad y el vencimiento del LOTE —y ése ya se mudó a
 * `products_business.expiration_date` en su propia migración—.
 *
 * Ponerlos en `products_business` obligaría a que cada droguería volviera a
 * escribir el registro INVIMA del mismo producto, que es justo lo que el
 * catálogo compartido existe para evitar.
 *
 * --------------------------------------------------------------------------
 * `requiere_formula` LLEVA DEFECTO Y NO ES NULLABLE.
 *
 * Un booleano que admite nulo tiene tres estados —sí, no, y no sabemos— y la
 * pantalla sólo puede pintar dos: «no sabemos» y «no requiere» acabarían
 * viéndose igual. Si se van a ver igual, que sean lo mismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_products', function (Blueprint $tabla) {
            $tabla->string('content', 80)->nullable()->after('dosage');
            $tabla->string('registro_invima', 40)->nullable()->after('presentation');
            $tabla->boolean('requiere_formula')->default(false)->after('registro_invima');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_products', function (Blueprint $tabla) {
            $tabla->dropColumn(['content', 'registro_invima', 'requiere_formula']);
        });
    }
};
