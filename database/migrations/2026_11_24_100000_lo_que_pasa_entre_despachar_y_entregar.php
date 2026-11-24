<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LO QUE PASA ENTRE DESPACHAR Y ENTREGAR.
 *
 * Un pedido en camino tenía dos salidas: entregado, o quedarse en camino para
 * siempre. El código lo decía sin rodeos --«NO SE CANCELA UN PEDIDO YA
 * DESPACHADO… quién le paga ese viaje es una decisión del negocio que nadie ha
 * tomado»--. La decisión ya está tomada, y esto es lo que necesita:
 *
 *  · NOVEDADES: el domiciliario no pudo entregar --el cliente no contesta, la
 *    dirección no existe, tuvo un accidente--. Queda escrito QUÉ pasó, DÓNDE
 *    y CUÁNDO, y el pedido vuelve al negocio (estado 6, «devolviendo») hasta
 *    que el negocio confirma que recibió el producto y la plata.
 *
 *  · EL PIN DE ENTREGA: tres dígitos que el comprador ve en su app y le dice
 *    al domiciliario en la puerta. Sin él una entrega es la palabra del
 *    domiciliario, y «nunca me llegó» no tiene con qué contestarse. Lleva un
 *    contador de intentos: tres dígitos se adivinan probando.
 *
 *  · «LLEGUÉ»: la hora en que el domiciliario avisó que estaba en la puerta.
 *
 *  · CON QUÉ PAGÓ Y CUÁNTO SE DEVOLVIÓ: la hoja de entrega ya lo calculaba y
 *    lo tiraba; sin esto no se puede cuadrar la plata del domiciliario
 *    contra lo que dijo el cliente al pedir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('novedades_de_entrega', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('domiciliary_id');
            // La clave del motivo (`NovedadesDeEntrega::MOTIVOS`), no un texto.
            $table->string('motivo', 40);
            $table->string('nota', 500)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            /*
             * El domicilio que se le reconoce al domiciliario aunque no hubo
             * entrega: el viaje lo hizo. Queda ESCRITO aquí; quién lo paga y
             * cómo entra en la liquidación es una decisión aparte, y hasta que
             * se tome no se toca el corte de cuentas.
             */
            $table->decimal('domicilio_reconocido', 12, 2)->default(0);
            $table->timestamp('devolucion_recibida_en')->nullable();
            $table->unsignedBigInteger('recibida_por')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('domiciliary_id');
        });

        Schema::table('orderssales', function (Blueprint $table) {
            $table->string('pin_entrega', 3)->nullable();
            $table->unsignedTinyInteger('pin_intentos')->default(0);
            $table->timestamp('llego_at')->nullable();
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change_given', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->dropColumn(['pin_entrega', 'pin_intentos', 'llego_at', 'cash_received', 'change_given']);
        });

        Schema::dropIfExists('novedades_de_entrega');
    }
};
