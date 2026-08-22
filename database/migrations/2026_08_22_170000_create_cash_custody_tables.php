<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CUSTODIA DEL EFECTIVO.
 *
 * Hasta ahora, entregar un pedido en efectivo creaba un `Payment` con
 * `status = 'approved'` y dejaba el pedido en `paid`, COMO SI EL DINERO
 * HUBIERA LLEGADO A LA PLATAFORMA. No había llegado a ninguna parte: estaba en
 * el bolsillo de quien entregó, sin un solo registro. Y la liquidación
 * empeoraba el hueco, porque le PAGABA su comisión sin COBRARLE lo recaudado.
 *
 * No se podía responder la pregunta más básica de una operación con efectivo:
 * ¿cuánto dinero nuestro tiene encima cada domiciliario ahora mismo?
 *
 * Dos tablas:
 *
 *  · `cash_movements` es el libro. Cada entrega en efectivo apunta un
 *    `recaudo` (lo que el domiciliario recibió) y cada consignación
 *    confirmada, una `consignacion`. El saldo es la suma. Se lleva como libro
 *    y no como una columna de saldo porque un saldo sin movimientos no se
 *    puede auditar: cuando no cuadre —y alguna vez no va a cuadrar— hay que
 *    poder ver de dónde salió cada peso.
 *
 *  · `cash_deposits` son los depósitos que el domiciliario declara, con su
 *    referencia bancaria, y que alguien confirma desde el panel. Declarar no
 *    es lo mismo que entregar: el movimiento solo entra al libro cuando se
 *    confirma, o cualquiera saldaría su deuda escribiendo un número.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_deposits', function (Blueprint $table) {
            $table->id();

            // int y no bigint: `domiciliary.domiciliary_id` es `int unsigned`
            // y MySQL rechaza la foránea si los tipos no coinciden exacto.
            $table->unsignedInteger('domiciliary_id');
            $table->foreign('domiciliary_id')
                ->references('domiciliary_id')->on('domiciliary')
                ->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('reference', 100)->nullable();
            $table->timestamp('deposited_at')->nullable();
            $table->string('receipt_path', 255)->nullable();

            // pendiente · confirmada · rechazada
            $table->string('state', 20)->default('pendiente');

            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->foreign('confirmed_by')
                ->references('user_id')->on('user')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['domiciliary_id', 'state'], 'depositos_domi_estado_idx');
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();

            // int y no bigint: `domiciliary.domiciliary_id` es `int unsigned`
            // y MySQL rechaza la foránea si los tipos no coinciden exacto.
            $table->unsignedInteger('domiciliary_id');
            $table->foreign('domiciliary_id')
                ->references('domiciliary_id')->on('domiciliary')
                ->cascadeOnDelete();

            // recaudo · consignacion · ajuste
            $table->string('type', 20);

            /*
             * Positivo = el domiciliario debe más (recaudó).
             * Negativo = debe menos (consignó, o se le ajustó a favor).
             * Un solo signo evita tener que recordar en cada consulta qué
             * tipos suman y cuáles restan.
             */
            $table->decimal('amount', 12, 2);

            // Igual que arriba: `orderssales.orderSales_id` es `int unsigned`.
            $table->unsignedInteger('order_id')->nullable();
            $table->foreign('order_id')
                ->references('orderSales_id')->on('orderssales')
                ->nullOnDelete();

            $table->unsignedBigInteger('deposit_id')->nullable();
            $table->foreign('deposit_id')
                ->references('id')->on('cash_deposits')
                ->nullOnDelete();

            $table->string('notes', 500)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')
                ->references('user_id')->on('user')->nullOnDelete();

            $table->timestamps();

            $table->index(['domiciliary_id', 'created_at'], 'movimientos_domi_fecha_idx');

            /*
             * Un pedido no puede generar dos recaudos.
             *
             * `updateStatus` puede reintentarse —la app reintenta ante un
             * fallo de red— y sin esto el segundo intento duplicaría la deuda
             * del domiciliario.
             */
            $table->unique(['order_id', 'type'], 'movimiento_pedido_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_deposits');
    }
};
