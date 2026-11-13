<?php

/**
 * LAS CONSULTAS DEL CAMINO CALIENTE TIENEN QUE PODER USAR UN ÍNDICE.
 *
 * §24 pide comprobar los planes con `EXPLAIN` sobre el motor de verdad, y
 * hasta ahora no se podía: la suite corre en SQLite en memoria y el propio
 * §25 avisa de que SQLite no sirve para juzgar un plan. El workflow
 * `pruebas-mysql.yml` quitó ese bloqueo, así que estas comprobaciones corren
 * allí y se saltan en local.
 *
 * SE COMPRUEBA `possible_keys`, NO `key`, Y ESA ES LA DECISIÓN DE DISEÑO.
 *
 * `key` es el índice que el optimizador ELIGIÓ, y eso depende del volumen y
 * de la distribución: en una base de pruebas recién migrada, casi vacía,
 * MySQL escanea porque escanear es lo correcto, y una prueba sobre `key`
 * fallaría sin que nada estuviera mal. `possible_keys` es otra cosa:
 * «¿existe en el esquema un índice capaz de servir esta consulta?». Eso es
 * verdad a cualquier volumen.
 *
 * Y es justo la regresión que hay que cazar, porque es la invisible:
 *
 *   - alguien suelta un `dropIndex` en una migración
 *   - alguien envuelve la columna en una función —`whereDate('sale_date', …)`
 *     en vez de un rango— y el índice deja de poder usarse, que es el aviso
 *     de sargabilidad de §24 §3
 *
 * Las dos dejan la aplicación funcionando igual y las dos se notan meses
 * después, cuando la tabla ya creció. `possible_keys` vacío las caza el mismo
 * día.
 *
 * QUÉ CUBRE OTRO ARCHIVO, para que «saltada» no se lea como «sin comprobar».
 * De las dos regresiones de arriba, la PRIMERA --el `dropIndex` suelto, o un
 * `up()` que se queda a medias-- la caza
 * `LosIndicesDeclaradosExistenTest.php` en CUALQUIER motor y en cada corrida:
 * reproduce las migraciones, se queda con los 92 índices con nombre que
 * dejan puestos, y exige que estén en el esquema.
 *
 * Lo que de verdad solo puede comprobarse acá es la SEGUNDA: la pérdida de
 * sargabilidad. Envolver la columna en una función no cambia el esquema
 * --el índice sigue estando, intacto-- y solo el plan delata que ya no se
 * puede usar.
 *
 * CÓMO SE MIDIÓ LO QUE HAY DEBAJO. Con las dos migraciones del 10 de
 * noviembre de 2026 puestas, contra MySQL 8.0.42 y los datos de desarrollo,
 * una ventana de una semana: cada consulta examina exactamente las 49 filas
 * que devuelve. Con un rango de un MES la medición no decía nada, porque
 * octubre tiene media tabla y ahí el escaneo es el plan correcto — el aviso
 * de §24 sobre «tamaño y distribución» en carne propia.
 */

use Illuminate\Support\Facades\DB;

/** El plan de una consulta, o se salta la prueba si el motor no es MySQL. */
function planDe(string $sql, array $ligaduras = []): object
{
    if (DB::connection()->getDriverName() !== 'mysql') {
        test()->markTestSkipped('`EXPLAIN` sobre un plan real solo vale en MySQL; en SQLite miente (§25).');
    }

    return DB::select('explain '.$sql, $ligaduras)[0];
}

/** Los índices que PODRÍAN servir esa consulta, como lista. */
function indicesPosibles(object $plan): array
{
    return array_filter(explode(',', (string) ($plan->possible_keys ?? '')));
}

test('el informe por periodo de la plataforma puede usar un indice', function () {
    // `GeneradorDeReportes::pedidosDelPeriodo()` abre por aquí, y de él salen
    // el informe de ventas, el operacional y el de domiciliarios.
    $plan = planDe('select count(*) from orderssales o where o.sale_date between ? and ?', [
        '2026-10-06', '2026-10-13',
    ]);

    expect(indicesPosibles($plan))->toContain('ix_pedidos_fecha_venta');
});

test('el informe por periodo de una tienda puede usar su indice compuesto', function () {
    /*
     * El camino del tendero, y el que más tráfico tiene desde que
     * `/negocio/domiciliarios/rendimiento` se abrió al panel y a la app.
     *
     * El orden del compuesto es `(busines_id, sale_date)`: igualdad primero y
     * rango después. Al revés el `busines_id` ya no serviría para buscar, solo
     * para filtrar fila a fila.
     */
    $plan = planDe(
        'select count(*) from orderssales o where o.busines_id = ? and o.sale_date between ? and ?',
        [1, '2026-10-06', '2026-10-13'],
    );

    expect(indicesPosibles($plan))->toContain('ix_pedidos_negocio_fecha_venta');
});

test('el webhook de la pasarela puede encontrar el pago por indice', function () {
    /*
     * `BoldWebhookController::resolvePayment()`. Es el primer paso de cada
     * aviso de Bold, o sea de cada pago en línea.
     *
     * Antes del índice: `type=ALL`, `possible_keys` vacío, 116 filas
     * recorridas para encontrar una. Y si el webhook tarda, Bold reintenta, y
     * los reintentos caen encima: se recorre la misma tabla que se está
     * escribiendo.
     */
    $plan = planDe('select * from payments where provider = ? and provider_payment_id = ?', [
        'bold', 'TX-QUE-NO-EXISTE',
    ]);

    expect(indicesPosibles($plan))->toContain('ix_pagos_referencia_pasarela');
});

test('el tablero de la tienda sigue pudiendo usar el suyo', function () {
    /*
     * CONTROL, y no relleno. Si una prueba de estas falla hay dos
     * explicaciones —falta el índice, o `EXPLAIN` no dice lo que creemos— y
     * esta las separa: `ix_pedidos_negocio_estado` existe desde agosto y se
     * midió usándose (`type=ref`, 5 filas de 546). Si esta falla, el problema
     * es del método, no del índice.
     */
    $plan = planDe('select count(*) from orderssales o where o.busines_id = ? and o.state = ?', [1, 2]);

    expect(indicesPosibles($plan))->toContain('ix_pedidos_negocio_estado');
});

test('envolver la fecha en una funcion deja el indice inservible', function () {
    /*
     * EL AVISO DE SARGABILIDAD DE §24 §3, COMPROBADO Y NO CITADO.
     *
     * `whereDate('sale_date', $dia)` compila a `date(sale_date) = ?`, y
     * aplicar una función sobre la columna impide aprovechar el B-tree.
     * Funciona igual, devuelve lo mismo, y recorre la tabla entera.
     *
     * Esta prueba fija que la diferencia es REAL en este esquema, para que el
     * consejo no sea una cita de un documento: la misma pregunta, escrita
     * como rango, sí puede usar el índice; escrita con la función, no.
     */
    $conFuncion = planDe('select count(*) from orderssales o where date(o.sale_date) = ?', ['2026-10-06']);
    $conRango = planDe('select count(*) from orderssales o where o.sale_date >= ? and o.sale_date < ?', [
        '2026-10-06', '2026-10-07',
    ]);

    expect(indicesPosibles($conFuncion))->toBe([]);
    expect(indicesPosibles($conRango))->toContain('ix_pedidos_fecha_venta');
});
