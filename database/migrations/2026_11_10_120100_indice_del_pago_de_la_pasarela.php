<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * `payments.provider_payment_id`: POR DONDE ENTRA CADA AVISO DE BOLD, Y SIN
 * ÍNDICE.
 *
 * `BoldWebhookController::resolvePayment()` busca el pago con
 * `where('provider', 'bold')->where('provider_payment_id', $transactionId)`.
 * Es el primer paso de cada aviso que manda la pasarela, o sea de cada pago
 * en línea de la plataforma.
 *
 * MEDIDO CON `EXPLAIN` SOBRE MYSQL 8.0.42:
 *
 *   select * from payments where provider = 'bold'
 *     and provider_payment_id = 'TX-123'
 *   -->  type=ALL   possible_keys=NINGUNA
 *
 * `payments` solo tenía la clave primaria, las tres de sus claves ajenas y
 * una sobre `refund_status`. Ninguna toca estas dos columnas, así que cada
 * aviso recorría la tabla entera.
 *
 * POR QUÉ IMPORTA MÁS QUE UN INFORME LENTO. Si el webhook tarda, Bold
 * reintenta, y los reintentos llegan encima de los que ya estaban: la tabla
 * que se recorre es la misma que se está escribiendo. Es el patrón que ya
 * mordió con los tiempos de espera de `BoldService`, en el otro sentido.
 *
 * EL ORDEN `(provider, provider_payment_id)` es el de la consulta: dos
 * igualdades, así que sirve en cualquier orden, pero puesto así también
 * sirve a una consulta que solo filtre por `provider`.
 *
 * NO SE PONE `unique`, Y NO ES POR PEREZA. Debería serlo: dos pagos del mismo
 * proveedor con la misma referencia no tienen sentido. Pero un índice único
 * que choca con un duplicado **tumba el despliegue**, y los datos de
 * desarrollo no son los de producción. Antes de ponerlo, en el servidor:
 *
 *   select provider, provider_payment_id, count(*) n from payments
 *   where provider_payment_id is not null
 *   group by provider, provider_payment_id having n > 1;
 *
 * Si sale vacío, cambiarlo a `unique` es mejor que esto: es más rápido y deja
 * la regla escrita en la base en vez de en la cabeza de alguien. En
 * desarrollo sale vacío (0 repetidos de 119 pagos, 7 con la referencia nula,
 * que un único de MySQL admite sin problema).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['provider', 'provider_payment_id'], 'ix_pagos_referencia_pasarela');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('ix_pagos_referencia_pasarela');
        });
    }
};
