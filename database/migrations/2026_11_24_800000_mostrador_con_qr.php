<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COBRAR EN EL MOSTRADOR CON QR BRE-B.
 *
 * El vecino escanea el código con la app de su banco y paga. El cobro pasa
 * por Bold, igual que el QR de los pedidos: la plata entra a la cuenta de la
 * plataforma y la tienda la recibe en su liquidación, menos lo que cobra la
 * pasarela.
 *
 * Por eso la venta ahora tiene un estado de pago: las de efectivo nacen
 * pagadas; las de QR nacen esperando y solo cuentan --en «Cuánto te queda»,
 * en el ingreso del día, en la liquidación-- cuando Bold confirma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas_mostrador', function (Blueprint $t) {
            // pagada | esperando | rechazada | cancelada
            $t->string('estado_pago', 20)->default('pagada')->after('forma_de_pago');
            $t->string('bold_referencia', 60)->nullable()->unique()->after('estado_pago');
            $t->string('bold_transaccion', 80)->nullable()->after('bold_referencia');
            $t->longText('qr_payload')->nullable()->after('bold_transaccion');
            $t->timestamp('qr_expira_at')->nullable()->after('qr_payload');
            // Lo que se queda la pasarela: la tienda recibe el total menos esto.
            $t->decimal('comision_pasarela', 10, 2)->default(0)->after('qr_expira_at');
            // En qué liquidación se le pagó a la tienda.
            $t->unsignedBigInteger('settlement_id')->nullable()->index()->after('comision_pasarela');
        });

        Schema::table('settlements', function (Blueprint $t) {
            // Lo cobrado con QR en el mostrador: se le SUMA a la tienda.
            $t->decimal('counter_qr', 12, 2)->default(0)->after('counter_cash');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', fn (Blueprint $t) => $t->dropColumn('counter_qr'));
        Schema::table('ventas_mostrador', function (Blueprint $t) {
            $t->dropUnique(['bold_referencia']);
            $t->dropIndex(['settlement_id']);
            $t->dropColumn([
                'estado_pago', 'bold_referencia', 'bold_transaccion', 'qr_payload',
                'qr_expira_at', 'comision_pasarela', 'settlement_id',
            ]);
        });
    }
};
