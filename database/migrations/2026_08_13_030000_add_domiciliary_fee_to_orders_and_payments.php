<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que gana el domiciliario es una porción de la tarifa de domicilio (25%
 * por defecto, en services.domiciliary_share), no la tarifa completa.
 *
 * Se guarda el importe ya calculado en vez de aplicar el porcentaje al leer:
 * si mañana cambia la comisión, las entregas pasadas conservan lo que
 * realmente se pactó y los reportes históricos siguen cuadrando.
 *
 * Va en las dos tablas a propósito:
 *   - en la orden, para poder mostrárselo al domiciliario desde que ve el
 *     pedido, sin esperar a que exista un pago;
 *   - en el pago, porque es de ahí que salen los cálculos de ingresos.
 */
return new class extends Migration
{
    public function up(): void
    {
        $porcentaje = (float) config('services.domiciliary_share', 0.25);

        Schema::table('orderssales', function (Blueprint $table) {
            $table->decimal('domiciliary_fee', 10, 2)->default(0)->after('domicilio');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('domiciliary_fee', 10, 2)->default(0)->after('domicilio');
        });

        DB::update(
            'UPDATE orderssales SET domiciliary_fee = ROUND(domicilio * ?)',
            [$porcentaje]
        );

        DB::update(
            'UPDATE payments SET domiciliary_fee = ROUND(domicilio * ?)',
            [$porcentaje]
        );
    }

    public function down(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->dropColumn('domiciliary_fee');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('domiciliary_fee');
        });
    }
};
