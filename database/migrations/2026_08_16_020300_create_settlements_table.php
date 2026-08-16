<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liquidaciones: lo que hay que PAGARLE a cada negocio y a cada domiciliario.
 *
 * El panel tenía "Pagos" —lo que entró— pero no su contraparte. El corte se
 * armaba a mano en una hoja de cálculo cruzando pedidos entregados con
 * comisiones, que es donde se cometen los errores que después nadie puede
 * explicar.
 *
 * El dato ya estaba: cada pedido guarda congelados `subtotal`, `domicilio`,
 * `domiciliary_fee` y `discount` desde que se creó. Esto solo los agrupa por
 * periodo y deja constancia del corte.
 *
 * LOS MONTOS SE CONGELAN AL GENERAR
 * Una liquidación no se recalcula al abrirla. Si mañana cambia la comisión de
 * la plataforma, el corte de agosto tiene que seguir diciendo lo que se pagó en
 * agosto. Recalcular al vuelo reescribiría la historia contable, que es
 * exactamente lo que un corte existe para impedir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();

            // A quién se le paga. Se usa una sola tabla con `type` en vez de
            // dos porque el ciclo es idéntico —generar, aprobar, pagar— y
            // duplicarlo obligaría a mantener dos pantallas gemelas.
            $table->enum('type', ['business', 'domiciliary']);
            $table->unsignedInteger('business_id')->nullable();
            $table->unsignedInteger('domiciliary_id')->nullable();

            $table->date('period_start');
            $table->date('period_end');

            $table->unsignedInteger('orders_count')->default(0);

            /*
             * Desglose congelado. `gross` es lo que se vendió; `platform_fee`
             * lo que retiene la plataforma; `net_payable` lo que efectivamente
             * se transfiere. Guardar los tres y no solo el neto permite
             * explicarle a un negocio de dónde salió su número sin rehacer la
             * cuenta.
             */
            $table->decimal('gross', 14, 2)->default(0);
            $table->decimal('delivery_fees', 14, 2)->default(0);
            $table->decimal('discounts', 14, 2)->default(0);
            $table->decimal('platform_fee', 14, 2)->default(0);
            $table->decimal('net_payable', 14, 2)->default(0);

            // 0 borrador | 1 aprobada | 2 pagada | 3 anulada
            $table->tinyInteger('state')->default(0);

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->string('notes', 500)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index(['type', 'state'], 'liq_tipo_estado_idx');
            $table->index(['period_start', 'period_end'], 'liq_periodo_idx');

            $table->foreign('business_id')->references('busines_id')->on('business')->cascadeOnDelete();
            $table->foreign('domiciliary_id')->references('domiciliary_id')->on('domiciliary')->cascadeOnDelete();
            $table->foreign('created_by')->references('user_id')->on('user')->nullOnDelete();
        });

        /**
         * Qué pedidos entraron en cada corte.
         *
         * Es lo que hace auditable la liquidación: sin el detalle, el neto es
         * un número que hay que creerse. El único (settlement_id, order_id)
         * impide que un pedido se cuente dos veces dentro del mismo corte.
         */
        Schema::create('settlement_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('settlement_id');
            $table->unsignedInteger('order_id');

            // Copia de lo que aportó ESE pedido, tal como estaba al generar.
            $table->decimal('gross', 12, 2)->default(0);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('net', 12, 2)->default(0);

            $table->unique(['settlement_id', 'order_id'], 'liq_pedido_unico');
            $table->index('order_id');

            $table->foreign('settlement_id')->references('id')->on('settlements')->cascadeOnDelete();
            $table->foreign('order_id')->references('orderSales_id')->on('orderssales')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_items');
        Schema::dropIfExists('settlements');
    }
};
