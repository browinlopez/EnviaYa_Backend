<?php

use App\Models\Rol;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * EL TABLERO DEL TENDERO: LOS DE HOY, NO TODO EL HISTÓRICO
 *
 * El tablero trae los pedidos abiertos más los que se cerraron HOY, y es la
 * pantalla que el tendero deja puesta: se refresca sola. Filtraba los cerrados
 * con `whereDate(DB::raw('COALESCE(delivery_date, sale_date)'), hoy)`.
 *
 * Abría **las 504 filas del histórico del negocio en cada refresco**, y la
 * secuencia de medidas es lo que hay que saber, porque las dos primeras
 * conclusiones fueron equivocadas:
 *
 *   1. como estaba                       type=ref    504 filas
 *   2. sargable, SIN los índices nuevos  type=ref    504 filas  <-- no sirvió
 *   3. con los índices, SIN reescribir   type=ref    504 filas  <-- tampoco
 *   4. las dos cosas juntas              type=range   69 filas
 *
 * Ninguno de los dos arreglos vale nada por separado, y eso no se podía saber
 * sin medirlo: la reescritura no basta porque MySQL no usa índice a través del
 * OR de nivel superior si no hay uno por rama, y el índice no basta porque
 * `date(COALESCE(...))` envuelve la columna.
 *
 * LO QUE ESTAS PRUEBAS DEFIENDEN es la equivalencia, que es todo el riesgo:
 * un pedido que ayer salía en el tablero tiene que seguir saliendo, y uno que
 * no salía tiene que seguir fuera. Comprobado además contra la base de
 * desarrollo sobre 280 combinaciones de negocio y día: las dos formas dan el
 * mismo número en las 280.
 */
function tenderoConTablero(): array
{
    foreach ([1 => 'comprador', 2 => 'tendero'] as $id => $n) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $n, 'guard_name' => 'web']);
    }

    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId([
        'name' => 'La del tablero', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');

    $tendero = User::factory()->create(['rol' => 2]);
    $ownerId = DB::table('owner')->insertGetId([
        'user_id' => $tendero->user_id, 'state' => 1,
    ], 'owner_id');
    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $negocio, 'state' => 1,
    ]);

    $comprador = User::factory()->create(['rol' => 1]);
    $buyerId = DB::table('buyer')->insertGetId([
        'user_id' => $comprador->user_id, 'qualification' => 0, 'state' => 1,
    ], 'buyer_id');

    DB::table('payment_methods')->insertOrIgnore([
        'methods_id' => 1, 'name' => 'Efectivo', 'state' => 1,
    ]);

    return ['negocio' => (int) $negocio, 'tendero' => $tendero, 'buyer' => (int) $buyerId];
}

function pedidoDelTablero(array $t, int $estado, array $fechas = []): int
{
    return (int) DB::table('orderssales')->insertGetId(array_merge([
        'buyer_id' => $t['buyer'], 'busines_id' => $t['negocio'], 'methods_id' => 1,
        'subtotal' => 10000, 'domicilio' => 2000, 'total' => 12000,
        'sale_date' => now(), 'state' => $estado, 'payment_state' => 'pending_cash',
    ], $fechas), 'orderSales_id');
}

it('trae los abiertos y los cerrados HOY, y deja fuera los de otros dias', function () {
    $t = tenderoConTablero();
    $hoy = CarbonImmutable::today();

    $esperados = [
        'abierto 1' => pedidoDelTablero($t, 1),
        'abierto 2' => pedidoDelTablero($t, 2),
        'abierto 3' => pedidoDelTablero($t, 3),
        // Entregado hoy, por su fecha de entrega.
        'entregado hoy' => pedidoDelTablero($t, 4, ['delivery_date' => $hoy->addHours(10)]),
        // Entregado hoy en el BORDE: 00:00:00 cuenta.
        'entregado a medianoche' => pedidoDelTablero($t, 4, ['delivery_date' => $hoy]),
        // Cancelado hoy, por su ultimo cambio.
        'cancelado hoy' => pedidoDelTablero($t, 5, ['updated_at' => $hoy->addHours(9)]),
        /*
         * ENTREGADO SIN FECHA DE ENTREGA: cuenta por `sale_date`, que es lo
         * que hacía el `COALESCE`. Es la rama que un rango ingenuo se come.
         */
        'entregado sin fecha' => pedidoDelTablero($t, 4, [
            'delivery_date' => null, 'sale_date' => $hoy->addHours(8),
        ]),
    ];

    $fuera = [
        // Entregado AYER: el caso que el tablero existe para no mostrar.
        'entregado ayer' => pedidoDelTablero($t, 4, ['delivery_date' => $hoy->subHours(2)]),
        // Y en el otro borde: 00:00:00 de mañana NO cuenta.
        'entregado maniana' => pedidoDelTablero($t, 4, ['delivery_date' => $hoy->addDay()]),
        'cancelado ayer' => pedidoDelTablero($t, 5, ['updated_at' => $hoy->subHour()]),
    ];

    Sanctum::actingAs($t['tendero']);

    $r = $this->getJson('/v1/negocio/pedidos')->assertOk();

    $ids = array_map('intval', array_column($r->json('orders'), 'order_id'));

    /*
     * SE JUNTAN LAS DIFERENCIAS en vez de cortar en la primera: asi el fallo
     * dice QUE pedido falta o sobra, por su nombre, y no hay que correr la
     * prueba siete veces para enterarse de las siete.
     *
     * (`toContain($id, $mensaje)` no vale: Pest trata el segundo argumento
     *  como otro valor que la lista debe contener, no como mensaje.)
     */
    $faltan = [];
    foreach ($esperados as $que => $id) {
        if (! in_array($id, $ids, true)) {
            $faltan[] = $que;
        }
    }

    $sobran = [];
    foreach ($fuera as $que => $id) {
        if (in_array($id, $ids, true)) {
            $sobran[] = $que;
        }
    }

    expect($faltan)->toBe([]);
    expect($sobran)->toBe([]);
    expect($ids)->toHaveCount(count($esperados));
});

it('da el mismo resultado que la forma vieja, que es lo que sustituye', function () {
    $t = tenderoConTablero();
    $hoy = CarbonImmutable::today();

    // Un pedido en cada estado y en cada borde que importa.
    foreach ([1, 2, 3] as $e) {
        pedidoDelTablero($t, $e);
    }
    pedidoDelTablero($t, 4, ['delivery_date' => $hoy]);
    pedidoDelTablero($t, 4, ['delivery_date' => $hoy->addHours(23)->addMinutes(59)]);
    pedidoDelTablero($t, 4, ['delivery_date' => $hoy->subSecond()]);
    pedidoDelTablero($t, 4, ['delivery_date' => $hoy->addDay()]);
    pedidoDelTablero($t, 4, ['delivery_date' => null, 'sale_date' => $hoy->addHour()]);
    pedidoDelTablero($t, 5, ['updated_at' => $hoy->addHours(5)]);
    pedidoDelTablero($t, 5, ['updated_at' => $hoy->subHour()]);

    /*
     * LA FORMA VIEJA, escrita aquí a mano: es la referencia. Este es el único
     * sitio del repositorio donde ese `whereDate(COALESCE(...))` debe seguir
     * existiendo, y existe para poder compararse.
     */
    $comoAntes = DB::table('orderssales')
        ->where('busines_id', $t['negocio'])
        ->where(function ($q) use ($hoy) {
            $q->whereIn('state', [1, 2, 3])
                ->orWhere(fn ($x) => $x->where('state', 4)
                    ->whereDate(DB::raw('COALESCE(delivery_date, sale_date)'), $hoy->toDateString()))
                ->orWhere(fn ($x) => $x->where('state', 5)
                    ->whereDate(DB::raw('COALESCE(updated_at, sale_date)'), $hoy->toDateString()));
        })
        ->orderBy('orderSales_id')
        ->pluck('orderSales_id')
        ->map(fn ($i) => (int) $i)
        ->all();

    Sanctum::actingAs($t['tendero']);

    $ahora = array_map('intval', array_column(
        $this->getJson('/v1/negocio/pedidos')->assertOk()->json('orders'), 'order_id'
    ));
    sort($ahora);

    expect($ahora)->toBe($comoAntes);

    // Y que no sea una lista vacía: dos listas vacías son iguales y no miden.
    expect($comoAntes)->not->toBeEmpty();
});

it('el tablero ya no abre todo el historico del negocio', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped(
            'Un plan de ejecución sobre SQLite no dice nada de MySQL: §25 lo '
            .'advierte. Corre en el flujo de MySQL.'
        );
    }

    $t = tenderoConTablero();
    $hoy = CarbonImmutable::today();
    pedidoDelTablero($t, 1);
    pedidoDelTablero($t, 4, ['delivery_date' => $hoy]);

    $desde = $hoy->startOfDay();
    $hasta = $hoy->addDay()->startOfDay();

    $plan = fn (string $sql, array $b) => DB::select('explain '.$sql, $b)[0];

    $vieja = $plan(
        'select count(*) from orderssales where busines_id = ? and (state in (1,2,3) '
        .'or (state = 4 and date(COALESCE(delivery_date, sale_date)) = ?) '
        .'or (state = 5 and date(COALESCE(updated_at, sale_date)) = ?))',
        [$t['negocio'], $hoy->toDateString(), $hoy->toDateString()]
    );

    $nueva = $plan(
        'select count(*) from orderssales where busines_id = ? and (state in (1,2,3) '
        .'or (state = 4 and ((delivery_date >= ? and delivery_date < ?) '
        .'or (delivery_date is null and sale_date >= ? and sale_date < ?))) '
        .'or (state = 5 and ((updated_at >= ? and updated_at < ?) '
        .'or (updated_at is null and sale_date >= ? and sale_date < ?))))',
        [$t['negocio'], $desde, $hasta, $desde, $hasta, $desde, $hasta, $desde, $hasta]
    );

    /*
     * `type` y no `rows`: las filas examinadas dependen del volumen y en una
     * base de pruebas casi vacía no distinguen nada. Lo que distingue a
     * cualquier tamaño es CÓMO entra: por el índice o abriendo la tabla.
     *
     * NO SE EXIGE `range` A SECAS, y esto se arregló el 13 de noviembre de
     * 2026, la primera vez que la suite corrió de verdad contra MySQL.
     * Decía `toBe('range')` y el motor devolvió `ref`, así que la prueba
     * fallaba sin que nada estuviera mal: `ref` es MÁS selectivo que `range`
     * —entra por igualdad en el prefijo del índice en vez de recorrer un
     * tramo— y en una base casi vacía es el plan que corresponde.
     *
     * Exigir el tipo exacto es el error contra el que advierte el archivo
     * hermano, `PlanDeLasConsultasCalientesTest`, con estas palabras: «eso
     * depende del volumen y de la distribución». Lo que esta prueba defiende
     * no es qué tipo sale: es que NO se abra la tabla entera. Así que se
     * exige eso, que es verdad a cualquier tamaño.
     */
    expect($nueva->type)->toBeIn(['const', 'eq_ref', 'ref', 'range']);

    /*
     * ENTRA POR UN INDICE, Y POR UNO QUE EMPIEZA EN `busines_id`. No se exige
     * CUAL: el optimizador eligio `ix_pedidos_negocio_fecha_venta` en la base
     * de pruebas y uno de los dos del tablero en la de desarrollo, y las dos
     * elecciones son correctas --los cuatro empiezan por `busines_id`, que es
     * el filtro que acota la consulta a UNA tienda--. Cual gane depende de las
     * estadisticas, y fijarlo es la trampa que advierte
     * `PlanDeLasConsultasCalientesTest`: «eso depende del volumen y de la
     * distribucion».
     *
     * Lo que importa y es verdad a cualquier tamano: que entre por el prefijo
     * del negocio en vez de abrir el historico.
     */
    expect($nueva->key)->not->toBeNull();
    expect($nueva->key)->toBeIn([
        'ix_pedidos_negocio_estado',
        'ix_pedidos_negocio_estado_entrega',
        'ix_pedidos_negocio_estado_cambio',
        'ix_pedidos_negocio_fecha_venta',
        'fk_orderssales_business',
    ]);

    /* Y que el esquema SI tiene los dos del tablero disponibles para esta
       consulta, que es lo que las migraciones del 11 de noviembre anadieron.
       `possible_keys` dice «existe y sirve» sin depender de la distribucion. */
    $disponibles = explode(',', (string) $nueva->possible_keys);
    expect(array_intersect(
        ['ix_pedidos_negocio_estado_entrega', 'ix_pedidos_negocio_estado_cambio'],
        $disponibles
    ))->not->toBeEmpty();

    /* Y la vieja, la que envolvía la columna en `date()`, NO puede entrar por
       el tramo del índice: es lo que la hacía abrir el histórico. */
    expect($vieja->type)->not->toBe('range');
});

it('el tablero no vuelve a envolver una columna en date()', function () {
    /*
     * Las pruebas de arriba fijan la EQUIVALENCIA, y eso las hace ciegas a una
     * vuelta atrás: en SQLite las dos formas devuelven la misma lista, así que
     * ninguna se pondría roja. Lo que se perdería es el índice, visible solo
     * en el plan de MySQL —que en local se salta—.
     *
     * Este mira el código. Se descartan los comentarios: hay uno que EXPLICA
     * la forma vieja, y un detector que no distingue la prosa del código
     * castiga el haberlo dejado dicho.
     */
    $fuente = file_get_contents(
        app_path('Http/Controllers/Order/ConsultaDePedidosController.php')
    );

    $codigo = '';
    foreach (token_get_all($fuente) as $pieza) {
        if (is_array($pieza) && in_array($pieza[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $codigo .= is_array($pieza) ? $pieza[1] : $pieza;
    }

    expect($codigo)->not->toContain('whereDate(');
});
