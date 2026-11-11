<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOS DOS ÍNDICES DEL TABLERO DE PEDIDOS DEL TENDERO.
 *
 * `GET /v1/negocio/pedidos` trae los pedidos abiertos más los que se cerraron
 * HOY, y es la pantalla que el tendero deja puesta: se refresca sola.
 *
 * Abría **las 504 filas del histórico del negocio en cada refresco**. Medido
 * con `EXPLAIN` sobre la base de desarrollo, y lo que importa es la secuencia
 * de medidas, porque la primera conclusión fue equivocada:
 *
 *   1. como estaba                      type=ref    504 filas
 *   2. reescrito sargable, SIN índices   type=ref    504 filas  <-- no sirvió
 *   3. con estos índices, SIN reescribir type=ref    504 filas  <-- tampoco
 *   4. las dos cosas juntas             type=range   69 filas
 *
 * O sea que ni el índice solo ni la reescritura sola valen nada aquí, y eso
 * no se podía saber sin medirlo: la reescritura no basta porque MySQL no usa
 * un índice a través del OR de nivel superior si no hay uno que sirva para
 * cada rama, y el índice no basta porque `date(COALESCE(...))` envuelve la
 * columna y lo inutiliza.
 *
 * POR QUÉ `(busines_id, state, fecha)` Y EN ESE ORDEN. El negocio y el estado
 * entran por igualdad —son el `where` de siempre— y la fecha por rango. Un
 * índice sirve hasta la primera columna que se usa por rango, así que la fecha
 * tiene que ir al final; con la fecha delante, las igualdades no la pueden
 * aprovechar.
 *
 * `delivery_date` para los entregados y `updated_at` para los cancelados: son
 * las dos columnas por las que el tablero pregunta «¿se cerró hoy?», y son
 * distintas a propósito —un entregado tiene su fecha de entrega; un cancelado,
 * la de su último cambio—.
 *
 * NO SON ÚNICOS y no deben serlo: dos pedidos del mismo negocio se entregan en
 * el mismo instante todos los días.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->index(
                ['busines_id', 'state', 'delivery_date'],
                'ix_pedidos_negocio_estado_entrega'
            );

            $table->index(
                ['busines_id', 'state', 'updated_at'],
                'ix_pedidos_negocio_estado_cambio'
            );
        });
    }

    public function down(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->dropIndex('ix_pedidos_negocio_estado_entrega');
            $table->dropIndex('ix_pedidos_negocio_estado_cambio');
        });
    }
};
