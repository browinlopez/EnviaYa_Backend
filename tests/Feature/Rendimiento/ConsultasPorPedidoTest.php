<?php

/**
 * EL NÚMERO DE CONSULTAS NO PUEDE CRECER CON EL NÚMERO DE PEDIDOS.
 *
 * Es la prueba que §25 pide por su nombre: «mantener tests que detecten un
 * incremento anormal del número de consultas cuando se aumenta el número de
 * registros retornados».
 *
 * POR QUÉ ESTA Y NO UN PRESUPUESTO DE MILISEGUNDOS. Un N+1 no da error: da
 * la respuesta correcta y cien consultas. No lo cazan las otras 1127 pruebas
 * —todas pasaban con el N+1 dentro— y no lo cazaría un presupuesto de
 * latencia medido sobre una base de pruebas casi vacía, porque con tres
 * pedidos cien consultas tardan nada. Lo único que delata un N+1 sin
 * depender del volumen es **la pendiente**: pedir más registros y ver si el
 * número de consultas sube con ellos.
 *
 * Y es la única métrica de §25 que no depende del motor: el conteo de
 * consultas es idéntico en SQLite y en MySQL, así que esto corre en la suite
 * normal y no hace falta el flujo de MySQL.
 *
 * EL N+1 QUE EXISTÍA DE VERDAD, y por el que esta prueba está escrita:
 * `GET /orders/business` precargaba su propia lista de relaciones, copiada
 * del otro controlador que usa el mismo presentador, y le faltaba
 * `domiciliary.user`. `PedidoDeLaAppResource` lee
 * `$order->domiciliary->user->name`, así que cada pedido con repartidor
 * costaba DOS consultas de más. A cincuenta pedidos en el tablero, cien
 * consultas que nadie veía porque la respuesta era correcta.
 *
 * Lo encontró `Model::preventLazyLoading()` al encenderlo para medir: de
 * 1127 pruebas fallaron dos, y las dos señalaban aquí.
 */

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Una tienda con `$cuantos` pedidos, cada uno con su repartidor, su línea de
 * detalle y su dirección.
 *
 * Cada pedido lleva **su propio** comprador, repartidor, producto y
 * dirección, a propósito: si todos compartieran el mismo, Eloquent resolvería
 * la relación una vez y el N+1 no aparecería ni estando el defecto. La
 * prueba se vería verde sobre el código roto, que es la peor clase de prueba.
 */
function tiendaConPedidos(int $cuantos): array
{
    foreach ([1 => 'comprador', 2 => 'tendero', 3 => 'domiciliario'] as $id => $n) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $n, 'guard_name' => 'web']);
    }

    DB::table('payment_methods')->insertOrIgnore([
        'methods_id' => 1, 'name' => 'Efectivo', 'state' => 1,
    ]);

    $tendero = User::factory()->create(['rol' => 2]);
    $ownerId = DB::table('owner')->insertGetId(
        ['user_id' => $tendero->user_id, 'state' => 1], 'owner_id',
    );
    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId([
        'name' => 'La del tablero', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');
    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $negocio, 'state' => 1,
    ]);

    for ($i = 0; $i < $cuantos; $i++) {
        $comprador = User::factory()->create(['rol' => 1]);
        $buyer = DB::table('buyer')->insertGetId([
            'user_id' => $comprador->user_id, 'qualification' => 0, 'state' => 1,
        ]);

        $repartidor = User::factory()->create(['rol' => 3]);
        $domi = DB::table('domiciliary')->insertGetId([
            'user_id' => $repartidor->user_id, 'available' => 1,
            'qualification' => 0, 'state' => 1,
        ], 'domiciliary_id');

        $direccion = DB::table('user_address')->insertGetId([
            'user_id' => $comprador->user_id,
            'address' => 'Calle '.$i.' # 2-3',
            'state' => 1,
        ]);

        $producto = DB::table('products')->insertGetId([
            'name' => 'Arroz '.$i, 'state' => 1,
        ], 'products_id');

        $pedido = DB::table('orderssales')->insertGetId([
            'buyer_id' => $buyer, 'busines_id' => $negocio, 'methods_id' => 1,
            'domiciliary_id' => $domi, 'address_id' => $direccion,
            'subtotal' => 20000, 'domicilio' => 3000, 'total' => 23000,
            'domiciliary_fee' => 2000, 'sale_date' => now(),
            // En camino: el estado en que el tablero muestra al repartidor.
            'state' => 3, 'payment_state' => 'paid',
        ], 'orderSales_id');

        DB::table('orderssales_detail')->insert([
            'orderSales_id' => $pedido, 'product_id' => $producto,
            'amount' => 2, 'unit_price' => 10000,
        ]);
    }

    Sanctum::actingAs($tendero);

    return compact('tendero', 'negocio');
}

/**
 * Cuántas consultas cuesta pedir el tablero de esa tienda.
 *
 * SE PIDE DOS VECES Y SE CUENTA LA SEGUNDA, y hace falta: la primera petición
 * del proceso paga además `select value, key from platform_settings`, que es
 * el catálogo de ajustes y se cachea para el resto del proceso. Medido: la
 * primera llamada cuesta 16 y la segunda 15, con los mismos datos.
 *
 * Sin el calentamiento, la comparación de abajo mezcla esa consulta de una
 * vez con lo que de verdad se está midiendo, y la prueba falla por un motivo
 * que no tiene nada que ver con los pedidos. Quitar estas dos líneas la deja
 * inestable, no más simple.
 */
function consultasDelTablero(int $negocio): int
{
    test()->postJson('/v1/orders/business', ['business_id' => $negocio])->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->postJson('/v1/orders/business', ['business_id' => $negocio])->assertOk();

    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}

test('el tablero cuesta lo mismo con un pedido que con ocho', function () {
    /*
     * LA PRUEBA ES LA PENDIENTE, NO EL NÚMERO.
     *
     * No se fija un tope de consultas: ese número cambia legítimamente en
     * cuanto alguien añade un campo o una comprobación, y una prueba que se
     * rompe cada vez que el código cambia correctamente se acaba borrando.
     * Lo que no puede cambiar es que **sea el mismo** con uno y con ocho: ahí
     * está la diferencia entre precargar y no precargar.
     *
     * Con el N+1 que había: uno daba N consultas y ocho daban N+14 —dos por
     * pedido con repartidor, el domiciliario y su usuario—.
     */
    $uno = tiendaConPedidos(1);
    $conUno = consultasDelTablero($uno['negocio']);

    $ocho = tiendaConPedidos(8);
    $conOcho = consultasDelTablero($ocho['negocio']);

    expect($conOcho)->toBe($conUno);
});

test('el tablero devuelve los ocho pedidos, con su repartidor dentro', function () {
    /*
     * LA CONTRAPARTE, y sin ella la de arriba no vale: un endpoint que
     * devolviera una lista vacía también costaría el mismo número de
     * consultas con uno y con ocho, y pasaría en verde.
     *
     * Así que se comprueba que los ocho están y que el repartidor viene
     * dentro de cada uno — que es justo el dato que se cargaba de uno en uno.
     */
    $t = tiendaConPedidos(8);

    $r = test()->postJson('/v1/orders/business', ['business_id' => $t['negocio']])->assertOk();

    expect($r->json('orders'))->toHaveCount(8);

    foreach ($r->json('orders') as $pedido) {
        expect($pedido['domiciliary'])->not->toBeNull();
        expect($pedido['domiciliary']['name'])->not->toBeEmpty();
    }
});

test('el pais de la direccion sigue viajando, y sigue siendo null', function () {
    /*
     * UNA PRUEBA DE QUE NADA CAMBIÓ, que es justo lo que hay que fijar aquí.
     *
     * `UserAddress::country()` está roto: su clave local es `department_id` y
     * `user_address` no tiene esa columna, así que devuelve `null` siempre y
     * lo ha hecho desde que se escribió. Se quitó de la precarga —era una
     * consulta por petición que no podía devolver nada— y el campo se rellena
     * con `null` escrito a mano.
     *
     * Esta prueba fija las DOS mitades:
     *
     *   - la clave `country` SIGUE presente. Quitarla del todo obligaría a las
     *     dos apps publicadas a defenderse de un `undefined` en cada pantalla
     *     de dirección, y no se pueden actualizar a la vez.
     *   - y sigue valiendo `null`. Si alguien arregla la relación de verdad,
     *     esta prueba se pone roja — y tiene que ponerse: es un valor que los
     *     clientes publicados reciben nulo desde siempre, así que llenarlo es
     *     una decisión de producto y no un arreglo que se cuela en un
     *     refactor.
     *
     * El municipio y el departamento SÍ resuelven, y se comprueba al lado:
     * así se distingue «el país es null porque la relación está rota» de «la
     * dirección no tiene geografía».
     */
    $t = tiendaConPedidos(1);

    DB::table('countries')->insertOrIgnore(['id' => 1, 'name' => 'Colombia', 'iso_code' => 'CO']);
    DB::table('departments')->insertOrIgnore(['id' => 1, 'name' => 'Atlántico', 'country_id' => 1]);
    DB::table('municipalities')->insertOrIgnore(['id' => 1, 'name' => 'Barranquilla', 'department_id' => 1]);
    DB::table('user_address')->where('address_id', '>', 0)->update(['municipality_id' => 1]);

    $pedido = test()->postJson('/v1/orders/business', ['business_id' => $t['negocio']])
        ->assertOk()
        ->json('orders.0');

    expect($pedido['delivery_address'])->toHaveKey('country');
    expect($pedido['delivery_address']['country'])->toBeNull();

    // La contraparte: la geografía que SÍ funciona, funciona.
    expect($pedido['delivery_address']['municipality'])->toBe('Barranquilla');
    expect($pedido['delivery_address']['department'])->toBe('Atlántico');
});
