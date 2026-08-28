<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAS FORÁNEAS QUE LE FALTABAN AL NÚCLEO.
 *
 * La base tiene 118 foráneas y todas con su índice, pero repartidas de forma
 * desigual: lo construido este año —personal de conjunto, efectivo,
 * liquidaciones— las tiene; el núcleo heredado no. Y son justo las tablas más
 * usadas.
 *
 * YA COSTÓ UNA. El comprobante `FV-2026-000087`, por $18.500, apuntaba a un
 * pedido que no existe: `DemoPurgeSeeder` borraba pedidos sin tocar sus
 * comprobantes, y sin foránea nada lo impidió. Un documento contable emitido
 * sobre nada, que nadie descubre hasta que cuadra caja.
 *
 * Se añaden cinco, y una de ellas obliga a un cambio de tipo:
 * `products_business.busines_id` es `bigint unsigned` mientras
 * `business.busines_id` es `int unsigned`, y MySQL no acepta una foránea entre
 * tipos distintos. Los valores caben de sobra, así que el cambio es seguro.
 *
 * LAS REGLAS DE BORRADO, una por una:
 *
 *  · `products_business → business`  CASCADE. Una oferta de una tienda que ya
 *    no existe no le sirve a nadie.
 *
 *  · `products_business → products`  RESTRICT. Al revés no: borrar del
 *    catálogo maestro un producto que varias tiendas venden les vaciaría el
 *    estante sin avisar. Para dejar de ofrecerlo está poner existencias en
 *    cero, que es por tienda.
 *
 *  · `invoices → orderssales`        RESTRICT. Es la que faltaba. Un
 *    comprobante es un documento contable: si hay que borrar el pedido,
 *    primero se resuelve el comprobante —anulándolo—, no se le quita el suelo.
 *
 *  · `invoices → business` y `→ buyer`  SET NULL. Las dos columnas ya son
 *    nulables, y el comprobante guarda un `snapshot` con el nombre, el NIT y
 *    la dirección de las dos partes: sigue siendo legible aunque la cuenta
 *    desaparezca. Es el mismo criterio que ya usa `orderssales`.
 *
 *  · `orderssales → user_address`    SET NULL. Un pedido entregado no deja de
 *    haber ocurrido porque el comprador borre esa dirección de su libreta.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ------------------------------------------------------------------
           Antes de nada: lo que ya está roto, porque una foránea no se puede
           crear sobre datos que la violan.
           ------------------------------------------------------------------ */
        $this->limpiarReferenciasRotas();

        /* --- El pivote del catálogo -------------------------------------- */

        /*
         * El tipo primero: sin esto la foránea no se puede crear.
         *
         * Sólo en MySQL. SQLite —donde corren las pruebas— no tiene `MODIFY`
         * ni le importan los tipos de las columnas, así que allí no hay
         * desajuste que corregir.
         */
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products_business MODIFY busines_id INT UNSIGNED NOT NULL');
        }

        Schema::table('products_business', function (Blueprint $table) {
            $table->foreign('busines_id', 'fk_pb_negocio')
                ->references('busines_id')->on('business')
                ->cascadeOnDelete();

            $table->foreign('products_id', 'fk_pb_producto')
                ->references('products_id')->on('products')
                ->restrictOnDelete();
        });

        /* --- Los comprobantes -------------------------------------------- */

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('orderSales_id', 'fk_invoices_pedido')
                ->references('orderSales_id')->on('orderssales')
                ->restrictOnDelete();

            $table->foreign('busines_id', 'fk_invoices_negocio')
                ->references('busines_id')->on('business')
                ->nullOnDelete();

            $table->foreign('buyer_id', 'fk_invoices_comprador')
                ->references('buyer_id')->on('buyer')
                ->nullOnDelete();
        });

        /* --- La dirección de entrega del pedido --------------------------- */

        Schema::table('orderssales', function (Blueprint $table) {
            $table->foreign('address_id', 'fk_orderssales_direccion')
                ->references('address_id')->on('user_address')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orderssales', fn (Blueprint $t) => $t->dropForeign('fk_orderssales_direccion'));

        Schema::table('invoices', function (Blueprint $t) {
            $t->dropForeign('fk_invoices_comprador');
            $t->dropForeign('fk_invoices_negocio');
            $t->dropForeign('fk_invoices_pedido');
        });

        Schema::table('products_business', function (Blueprint $t) {
            $t->dropForeign('fk_pb_producto');
            $t->dropForeign('fk_pb_negocio');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products_business MODIFY busines_id BIGINT UNSIGNED NOT NULL');
        }
    }

    /**
     * Deja en NULL lo que apunta a filas que no existen.
     *
     * Se pone a NULL en vez de borrar la fila: un comprobante huérfano sigue
     * siendo un documento emitido, con su número y su importe, y hacerlo
     * desaparecer sería peor que dejarlo señalando a nada. Lo que se quita es
     * la referencia falsa.
     *
     * `orderSales_id` de `invoices` es el caso incómodo: es lo que ata el
     * comprobante a su venta, y dejarlo en NULL a secas convertiría el
     * documento en un papel suelto sin explicación. Por eso ahí no basta con
     * limpiar la referencia — se ANULA el comprobante, con su motivo escrito,
     * que es lo mismo que haría una persona desde el panel.
     */
    private function limpiarReferenciasRotas(): void
    {
        $rotos = DB::table('invoices as i')
            ->whereNotNull('i.orderSales_id')
            ->whereNotExists(fn ($q) => $q->from('orderssales as o')
                ->whereColumn('o.orderSales_id', 'i.orderSales_id'))
            ->get(['i.invoice_id', 'i.invoice_number', 'i.total', 'i.orderSales_id']);

        foreach ($rotos as $r) {
            /*
             * Se anula, no se borra: queda el número, el importe y el motivo.
             * Es exactamente lo que el panel hace cuando hay que retirar un
             * comprobante, y deja el rastro que un borrado no dejaría.
             */
            DB::table('invoices')->where('invoice_id', $r->invoice_id)->update([
                'orderSales_id' => null,
                'state'         => 0,
                'void_reason'   => 'Anulado al añadir la foránea: apuntaba al pedido '
                    . "#{$r->orderSales_id}, que no existe.",
                'voided_at'     => now(),
                'updated_at'    => now(),
            ]);
        }

        foreach (['busines_id' => 'business', 'buyer_id' => 'buyer'] as $col => $tabla) {
            $pk = $tabla === 'business' ? 'busines_id' : 'buyer_id';

            DB::table('invoices')
                ->whereNotNull($col)
                ->whereNotExists(fn ($q) => $q->from($tabla)->whereColumn("{$tabla}.{$pk}", "invoices.{$col}"))
                ->update([$col => null]);
        }

        DB::table('orderssales')
            ->whereNotNull('address_id')
            ->whereNotExists(fn ($q) => $q->from('user_address')
                ->whereColumn('user_address.address_id', 'orderssales.address_id'))
            ->update(['address_id' => null]);

        /*
         * El pivote no admite NULL en ninguna de las dos: una oferta sin
         * producto o sin tienda no es nada. Ahí sí se borra la fila.
         */
        DB::table('products_business')
            ->whereNotExists(fn ($q) => $q->from('products')
                ->whereColumn('products.products_id', 'products_business.products_id'))
            ->orWhereNotExists(fn ($q) => $q->from('business')
                ->whereColumn('business.busines_id', 'products_business.busines_id'))
            ->delete();
    }
};
