<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * COMPLETA LA TABLA DE FACTURAS
 *
 * `invoices` existía desde el diseño original con once columnas y cero filas:
 * alguien la previó y nunca se construyó. Se conserva —y su modelo— en vez de
 * crear otra, porque la forma que tiene es correcta.
 *
 * Lo que le falta es lo que convierte una fila en un COMPROBANTE:
 *
 * · El desglose completo. Tenía subtotal, descuento, IVA y total, pero no el
 *   domicilio ni la comisión del repartidor. Sin eso el total no cuadra con sus
 *   partes, y una factura cuyos números no suman no sirve para nada.
 *
 * · Una FOTO de los datos al momento de emitir. Si el negocio cambia de nombre
 *   o el producto de precio, la factura de marzo tiene que seguir diciendo lo
 *   que decía en marzo. Hoy se armaría con joins contra las tablas vivas, así
 *   que un cambio de hoy reescribiría el pasado.
 *
 * · El estado, para poder ANULAR. Una factura emitida por error no se borra:
 *   se anula dejando constancia de quién y por qué. Borrarla abriría un hueco
 *   en el consecutivo.
 *
 * NO es una factura electrónica ante la DIAN: es el comprobante interno de la
 * operación. Cuando llegue la conexión con la DIAN, esta tabla es la base —le
 * faltará el CUFE, la resolución y el XML firmado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            /*
             * A quién y de quién. El pedido ya los tiene, pero la factura se
             * consulta y se filtra por negocio miles de veces: resolverlo con
             * un join en cada listado es pagar el mismo precio siempre.
             */
            $table->unsignedInteger('busines_id')->nullable()->after('orderSales_id');
            $table->unsignedInteger('buyer_id')->nullable()->after('busines_id');

            // El desglose que faltaba para que el total cuadre con sus partes.
            $table->decimal('domicilio', 10, 2)->default(0)->after('descuento');
            $table->decimal('domiciliary_fee', 10, 2)->default(0)->after('domicilio');

            $table->string('currency', 3)->default('COP')->after('total');

            /*
             * La foto de los datos al emitir: negocio, comprador y renglones.
             * Es lo que hace que la factura no cambie cuando cambian las tablas.
             */
            $table->json('snapshot')->nullable()->after('currency');

            // 1 emitida · 0 anulada. Sin borrado: un hueco en el consecutivo es
            // exactamente lo que un comprobante no puede tener.
            $table->tinyInteger('state')->default(1)->after('snapshot');
            $table->string('void_reason', 255)->nullable()->after('state');
            $table->timestamp('voided_at')->nullable()->after('void_reason');
            $table->unsignedBigInteger('voided_by')->nullable()->after('voided_at');

            $table->string('notes', 500)->nullable()->after('voided_by');

            $table->timestamps();
        });

        /*
         * Índices. El único en `invoice_number` es la red de seguridad del
         * consecutivo: si dos entregas simultáneas calculan el mismo número, la
         * segunda falla y se reintenta, en vez de quedar dos facturas con el
         * mismo número — que es un problema que nadie descubre hasta la
         * auditoría.
         */
        Schema::table('invoices', function (Blueprint $table) {
            $table->unique('invoice_number', 'invoices_number_unique');
            // Una factura por pedido: la emisión es idempotente y esto lo
            // garantiza aunque el disparador se ejecute dos veces.
            $table->unique('orderSales_id', 'invoices_order_unique');
            $table->index(['busines_id', 'invoice_date'], 'invoices_business_date_idx');
            $table->index('invoice_date', 'invoices_date_idx');
        });

        // La clave primaria venía sin autoincremento en el diseño original, así
        // que cada insert habría tenido que calcular el id a mano.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $sinAuto = DB::selectOne("
                SELECT EXTRA FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'invoices'
                   AND COLUMN_NAME = 'invoice_id'
            ");

            if ($sinAuto && !str_contains(strtolower($sinAuto->EXTRA ?? ''), 'auto_increment')) {
                DB::statement('ALTER TABLE invoices MODIFY invoice_id INT NOT NULL AUTO_INCREMENT');
            }
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_number_unique');
            $table->dropUnique('invoices_order_unique');
            $table->dropIndex('invoices_business_date_idx');
            $table->dropIndex('invoices_date_idx');

            $table->dropColumn([
                'busines_id', 'buyer_id', 'domicilio', 'domiciliary_fee',
                'currency', 'snapshot', 'state', 'void_reason', 'voided_at',
                'voided_by', 'notes', 'created_at', 'updated_at',
            ]);
        });
    }
};
