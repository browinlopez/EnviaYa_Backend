<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las tres cifras de dinero que faltaban en el pedido.
 *
 * Se guardan EN LA FILA, congeladas al crearla, por la misma razón que ya se
 * hace con `domicilio`, `domiciliary_fee` y `promised_minutes`: si se
 * calcularan al consultar, cambiar un ajuste en el panel reescribiría el
 * pasado. Subir la comisión del 3 % al 5 % le cambiaría a un negocio lo que ya
 * se le liquidó el mes anterior, y nadie podría explicar la diferencia.
 *
 *  · `platform_fee`      lo que la plataforma le retiene al negocio.
 *  · `delivery_subsidy`  lo que la plataforma pone cuando el domicilio va
 *                        gratis o rebajado, para que el domiciliario cobre
 *                        igual. Es el costo real de la promoción, y sin
 *                        columna propia no habría forma de medirlo.
 *  · `cash_due`          lo que el domiciliario queda debiendo por haber
 *                        cobrado en efectivo. Hasta ahora ese dinero
 *                        desaparecía del sistema: el pedido se marcaba pagado
 *                        y el efectivo quedaba en el bolsillo de quien
 *                        entregó, sin registro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            if (!Schema::hasColumn('orderssales', 'platform_fee')) {
                $table->decimal('platform_fee', 10, 2)->default(0)->after('domiciliary_fee');
            }

            if (!Schema::hasColumn('orderssales', 'delivery_subsidy')) {
                $table->decimal('delivery_subsidy', 10, 2)->default(0)->after('platform_fee');
            }

            if (!Schema::hasColumn('orderssales', 'cash_due')) {
                $table->decimal('cash_due', 10, 2)->default(0)->after('delivery_subsidy');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            foreach (['cash_due', 'delivery_subsidy', 'platform_fee'] as $columna) {
                if (Schema::hasColumn('orderssales', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
