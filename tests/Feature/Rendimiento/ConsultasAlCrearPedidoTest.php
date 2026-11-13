<?php

use Illuminate\Support\Facades\DB;

/**
 * LAS CONSULTAS DE CREAR UN PEDIDO
 *
 * El informe de `scripts/percentiles_de_latencia.php`, en su primera corrida
 * de verdad, señaló `POST v1/orders/orders` como la ruta con más consultas de
 * la plataforma. Se fue a mirar y el resultado fue, sobre todo, NEGATIVO:
 * veintiséis consultas para un pedido de una línea, y de todas ellas **una
 * sola repetida** —`business`, que se cargaba dos veces y ya no—. El resto es
 * trabajo real: las comprobaciones de existencia de la validación, los precios
 * del servidor, las promociones vigentes, el alta, el detalle, el inventario y
 * la precarga de la respuesta.
 *
 * Eso merece quedar fijado, porque un «26 consultas» sin más invita a que
 * alguien vuelva a buscar un N+1 que no está.
 *
 * ================================================================
 * LO QUE ESTO DESTAPÓ, Y VALE MÁS QUE EL AHORRO
 * ================================================================
 *
 * `config/audit.php` trae `'console' => false`, y **Pest corre en consola**.
 * O sea que en la suite NO se escribe ni una fila de auditoría, y en
 * producción se escribe una por cada modelo auditable que se crea o cambia.
 *
 * Medido aquí mismo: un pedido de una línea cuesta 26 consultas con la suite
 * tal como está configurada y 27 en producción; uno de cinco líneas, 33 contra
 * 39. La auditoría añade **una escritura por línea de pedido**, invisible para
 * cualquier medición hecha desde la suite.
 *
 * Es el mismo error que §25 señala con SQLite y los planes de ejecución, pero
 * en otro eje: no es el motor lo que falsea la medida, es la CONFIGURACIÓN del
 * entorno de pruebas. Toda cuenta de consultas de un camino de ESCRITURA
 * medida desde la suite se queda corta, y se queda corta justo en proporción
 * al tamaño del pedido. En los caminos de LECTURA no pasa: una consulta no
 * dispara auditoría.
 *
 * Por eso la pendiente se mide aquí con la auditoría ENCENDIDA, que es como
 * corre lo de verdad, y hay una prueba dedicada a que esa diferencia no se
 * pierda de vista.
 */
function pedidoDeLineas(int $cuantas, bool $auditando = true): int
{
    config(['audit.console' => $auditando]);

    $e = escenarioPedido();

    $lineas = [['product_id' => $e['productId'], 'amount' => 3]];

    for ($i = 2; $i <= $cuantas; $i++) {
        $pid = DB::table('products')->insertGetId(
            ['name' => 'Producto '.$i, 'state' => 1],
            'products_id'
        );

        DB::table('products_business')->insert([
            'busines_id' => $e['businessId'],
            'products_id' => $pid,
            'price' => 1000,
            'amount' => 100,
        ]);

        $lineas[] = ['product_id' => $pid, 'amount' => 2];
    }

    $consultas = 0;
    DB::listen(function () use (&$consultas) {
        $consultas++;
    });

    test()->postJson('/v1/orders/orders', cuerpoPedido($e, ['products' => $lineas]))
        ->assertCreated();

    return $consultas;
}

/*
 * UNA PETICION TIRADA ANTES DE MEDIR.
 *
 * `platform_settings` se cachea por PROCESO, asi que la primerisima peticion
 * paga una consulta que ninguna de las siguientes paga. Medir sin calentar
 * hace que el primer punto de la serie salga una consulta alto, y eso rompe
 * cualquier comparacion de escalones: la primera version de esta prueba daba
 * 11 y 12 donde el escalon real es 12 y 12, y el fallo estaba descrito en el
 * comentario de la prueba que lo cometia.
 */
function calentarElProceso(): void
{
    pedidoDeLineas(1);
}

it('crece de forma lineal con las lineas, no cuadratica', function () {
    /*
     * TRES TAMAÑOS Y NO DOS, porque con dos puntos cualquier curva parece una
     * recta. Lo que distingue un N+1 acotado de uno que se multiplica es si el
     * ESCALÓN se mantiene, y para ver un escalón hacen falta tres medidas.
     *
     * Y se empieza en cinco, no en uno: la primera petición de un proceso paga
     * cosas que luego están en caché —`platform_settings` se cachea por
     * proceso—, así que el primer punto trae una consulta de ruido que no
     * pertenece a la pendiente. Es la misma precaución que hizo falta al medir
     * el tablero de pedidos.
     */
    calentarElProceso();

    $cinco = pedidoDeLineas(5);
    $nueve = pedidoDeLineas(9);
    $trece = pedidoDeLineas(13);

    expect($nueve)->toBeGreaterThan($cinco);

    $primerEscalon = $nueve - $cinco;
    $segundoEscalon = $trece - $nueve;

    expect($segundoEscalon)->toBe($primerEscalon);
});

it('no gasta mas de tres consultas por linea de pedido', function () {
    /*
     * TRINQUETE, con las tres nombradas, porque un número sin desglose no se
     * puede defender ni corregir. Por cada línea van exactamente:
     *
     *   1. `insert into orderssales_detail` — una por línea. Agruparlas en un
     *      solo INSERT ahorraría n-1 consultas, y NO se hace aquí: `insert()`
     *      del constructor de consultas se salta los eventos del modelo, y
     *      `OrdersSalesDetail` extiende `Audit`, así que el detalle de los
     *      pedidos dejaría de auditarse. Eso es una decisión sobre qué queda
     *      registrado, no una optimización, y no se cuela en un refactor.
     *   2. `update products_business set amount = amount - ?` — una por
     *      producto. Cada línea descuenta una cantidad distinta, así que
     *      juntarlas pide un `CASE WHEN` por producto: más frágil que lo que
     *      ahorra.
     *   3. `insert into audits` — la fila de auditoría de la línea. ES LA QUE
     *      NO SE VE desde la suite.
     *
     * Si aparece una cuarta, esta prueba se pone roja. Y si alguien agrupa los
     * inserts —con la decisión de auditoría tomada— baja y sigue en verde: un
     * trinquete frena las regresiones, no las mejoras.
     */
    calentarElProceso();

    $porLinea = (pedidoDeLineas(13) - pedidoDeLineas(5)) / 8;

    expect($porLinea)->toBeLessThanOrEqual(3.0);
});

it('la suite mide MENOS consultas que produccion en los caminos de escritura', function () {
    /*
     * LA PRUEBA DE LA TRAMPA.
     *
     * No fija un número —cambiaría con cualquier mejora— sino la relación:
     * con la auditoría como la tiene configurada la suite (`console = false`,
     * y Pest es consola) se cuentan MENOS consultas que con la auditoría como
     * corre en producción, y la diferencia CRECE con el tamaño del pedido.
     *
     * Mientras esta prueba esté en verde, cualquiera que lea una cuenta de
     * consultas de una escritura medida en la suite sabe que el número de
     * verdad es mayor. El día que se ponga roja será porque se decidió auditar
     * también en consola, y entonces todas las demás medidas se vuelven
     * directas: tampoco es un fallo, es una noticia.
     */
    calentarElProceso();

    $comoLaSuite = pedidoDeLineas(9, auditando: false);
    $comoProduccion = pedidoDeLineas(9, auditando: true);

    expect($comoProduccion)->toBeGreaterThan($comoLaSuite);

    $huecoConNueve = $comoProduccion - $comoLaSuite;
    $huecoConUna = pedidoDeLineas(1, auditando: true) - pedidoDeLineas(1, auditando: false);

    expect($huecoConNueve)->toBeGreaterThan($huecoConUna);
});

it('no vuelve a pedir el negocio que el aviso del pedido ya habia cargado', function () {
    /*
     * `AvisoDePedidoNuevo::anunciar()` carga `business.owners` para mandar el
     * push, y la respuesta hacía `load('...', 'business', ...)`: la misma fila,
     * dos veces en la misma petición. Con `loadMissing` se pide una.
     *
     * Se cuenta la consulta concreta y no el total: el total lo mueve
     * cualquier cosa, y lo que esta prueba defiende es que esa fila no se pida
     * dos veces.
     */
    $e = escenarioPedido();

    /*
     * SE QUITAN LAS COMILLAS DE LOS IDENTIFICADORES antes de buscar, y hace
     * falta: SQLite escribe `"business"` y MySQL escribe `` `business` ``, así
     * que esta subcadena no casaba NUNCA sobre MySQL. `$veces` se quedaba en 0
     * y la prueba fallaba sin que nada estuviera mal en el código —lo destapó
     * la primera corrida de la suite contra MySQL, el 13 de noviembre de
     * 2026—.
     *
     * Lo que la prueba defiende no depende del motor: que esa fila no se pida
     * dos veces en la misma petición.
     */
    $veces = 0;
    DB::listen(function ($q) use (&$veces) {
        $sql = str_replace(['"', '`'], '', $q->sql);

        if (str_contains($sql, 'from business where business.busines_id in')) {
            $veces++;
        }
    });

    $this->postJson('/v1/orders/orders', cuerpoPedido($e))->assertCreated();

    expect($veces)->toBe(1);
});

it('la respuesta del pedido nuevo no gana campos por cargar de mas', function () {
    /*
     * EL RIESGO DE `loadMissing`, cerrado.
     *
     * Con `load`, Eloquent reemplazaba `business` y tiraba el `owners` que
     * había traído el aviso. Con `loadMissing` el negocio llega con sus dueños
     * dentro, y si la respuesta se construyera serializando el modelo, esos
     * dueños —con su `user_id` y su cadena de propiedad— aparecerían en el
     * JSON que recibe la app publicada.
     *
     * No ocurre porque `toApi()` arma `business` campo por campo. Esta prueba
     * lo fija: la forma de `business` en la respuesta es exactamente esa lista
     * y nada más.
     */
    $e = escenarioPedido();

    $r = $this->postJson('/v1/orders/orders', cuerpoPedido($e))->assertCreated();

    $negocio = $r->json('order.business');

    expect(array_keys($negocio))->toBe([
        'business_id', 'name', 'address', 'latitude', 'longitude',
        'phone', 'city', 'state', 'logo',
    ]);

    expect($negocio)->not->toHaveKey('owners');
});
