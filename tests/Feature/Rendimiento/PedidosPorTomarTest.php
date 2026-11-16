<?php

use App\Http\Resources\Pedido\PedidoDeLaAppResource;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * LOS PEDIDOS «POR TOMAR» NO CARGAN LO QUE NO ENSEÑAN
 *
 * `GET /v1/domiciliaries/pedidos` devuelve dos mitades: los que ya me
 * asignaron —con la dirección y el teléfono de quien compró, porque tengo que
 * llegar a su puerta— y los que están listos en mis tiendas y nadie ha tomado,
 * que van **sin** nada de esa persona: veo de qué tienda es y cuánto suma, que
 * es lo que hace falta para decidir si lo tomo.
 *
 * Las dos mitades precargaban la MISMA lista de relaciones, así que en la
 * segunda se traían de la base el comprador, su usuario, la dirección y sus
 * tres hermanas para tirarlos al serializar. Medido contra la base de
 * desarrollo: 34 consultas, de las que seis eran esas.
 *
 * Lo que se fija aquí es que la respuesta no cambie —esos campos ya salían
 * nulos— y la sutileza de la que depende todo: que leer `$order->buyer` esté
 * DETRÁS del interruptor en la condición, porque si se reordena, la relación
 * que ya no se precarga se cargaría perezosamente una por pedido.
 */
function escenarioDeReparto(int $porTomar = 2, int $mios = 1): array
{
    foreach ([1 => 'comprador', 2 => 'tendero', 3 => 'domiciliario'] as $id => $n) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $n, 'guard_name' => 'web']);
    }

    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId([
        'name' => 'La del reparto', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');

    $repartidor = User::factory()->create(['rol' => 3]);
    $domiId = DB::table('domiciliary')->insertGetId([
        'user_id' => $repartidor->user_id, 'available' => 1, 'qualification' => 0, 'state' => 1,
    ], 'domiciliary_id');
    DB::table('business_domiciliary')->insert([
        'busines_id' => $negocio, 'domiciliary_id' => $domiId, 'state' => 1,
    ]);

    DB::table('payment_methods')->insertOrIgnore([
        'methods_id' => 1, 'name' => 'Efectivo', 'state' => 1,
    ]);

    $pedido = function (int $estado, ?int $asignado) use ($negocio) {
        $u = User::factory()->create(['rol' => 1]);
        $buyerId = DB::table('buyer')->insertGetId([
            'user_id' => $u->user_id, 'qualification' => 0, 'state' => 1,
        ], 'buyer_id');
        $dir = DB::table('user_address')->insertGetId([
            'user_id' => $u->user_id, 'address' => 'Calle de la prueba', 'state' => 1,
        ], 'address_id');

        return DB::table('orderssales')->insertGetId([
            'buyer_id' => $buyerId, 'busines_id' => $negocio, 'address_id' => $dir,
            'methods_id' => 1, 'subtotal' => 10000, 'domicilio' => 2000, 'total' => 12000,
            'sale_date' => now(), 'state' => $estado, 'payment_state' => 'pending_cash',
            'domiciliary_id' => $asignado,
        ], 'orderSales_id');
    };

    for ($i = 0; $i < $porTomar; $i++) {
        $pedido(2, null);
    }

    for ($i = 0; $i < $mios; $i++) {
        $pedido(3, (int) $domiId);
    }

    return ['repartidor' => $repartidor, 'negocio' => (int) $negocio, 'domiId' => (int) $domiId];
}

it('la lista sin comprador se DERIVA de la completa, no se copia', function () {
    $todas = PedidoDeLaAppResource::relaciones();
    $sin = PedidoDeLaAppResource::relacionesSinComprador();

    // Subconjunto: nada aparece ahí que no esté en la lista buena.
    expect(array_diff($sin, $todas))->toBe([]);

    // Y quita exactamente lo del comprador y su dirección, ni más ni menos.
    $quitadas = array_values(array_diff($todas, $sin));
    sort($quitadas);
    expect($quitadas)->toBe([
        'address.alias', 'address.department', 'address.municipality', 'buyer.user',
    ]);

    /*
     * LO QUE DE VERDAD IMPORTA: que se derive. Si mañana alguien añade una
     * relación a `relaciones()`, tiene que aparecer también aquí. Una copia
     * literal se queda vieja y el síntoma no es un error: es una carga
     * perezosa en producción, donde `preventLazyLoading` está apagado.
     */
    expect(count($sin))->toBe(count($todas) - 4);
});

it('un pedido por tomar no trae ni comprador ni direccion, como antes', function () {
    $e = escenarioDeReparto(porTomar: 2, mios: 1);
    Sanctum::actingAs($e['repartidor']);

    $r = $this->getJson('/v1/domiciliaries/pedidos')->assertOk();

    $pedidos = $r->json('orders');
    expect($pedidos)->toHaveCount(3);

    $porTomar = array_values(array_filter($pedidos, fn ($p) => (int) $p['state'] === 2));
    $mios = array_values(array_filter($pedidos, fn ($p) => (int) $p['state'] === 3));

    expect($porTomar)->toHaveCount(2);
    expect($mios)->toHaveCount(1);

    foreach ($porTomar as $p) {
        // Las CLAVES siguen ahí —quitarlas obligaría a la app a defenderse de
        // un `undefined`— y siguen valiendo null, que es lo que valían.
        expect($p)->toHaveKeys(['buyer', 'delivery_address']);
        expect($p['buyer'])->toBeNull();
        expect($p['delivery_address'])->toBeNull();
        // Y lo que sí necesita para decidir, presente.
        expect($p['business'])->not->toBeNull();
        expect($p['total'])->not->toBeNull();
    }

    // El mío sí los trae: es la mitad que no cambió.
    expect($mios[0]['buyer'])->not->toBeNull();
    expect($mios[0]['delivery_address'])->not->toBeNull();
});

it('no se cuela una carga perezosa en la mitad sin comprador', function () {
    /*
     * ESTA ES LA PRUEBA QUE PROTEGE EL ARREGLO.
     *
     * `formatear()` decide con `($conDatosDelComprador && $order->buyer)`. El
     * `&&` corta por la izquierda, así que con el interruptor en false
     * `$order->buyer` NO se evalúa y da igual que no esté precargado. Si
     * alguien reordena esa condición —`(! $order->pickup && $conDatos...)`—
     * la relación se cargaría una vez por pedido, y en producción
     * `preventLazyLoading` está apagado: no habría error, solo consultas.
     *
     * En las pruebas sí está encendido, así que una carga perezosa lanza
     * `LazyLoadingViolationException` y esto se pone rojo. La prueba no afirma
     * nada: que termine ES el resultado.
     */
    expect(Model::preventsLazyLoading())->toBeTrue();

    $e = escenarioDeReparto(porTomar: 3, mios: 0);
    Sanctum::actingAs($e['repartidor']);

    $this->getJson('/v1/domiciliaries/pedidos')->assertOk();
});

it('el coste no crece con los pedidos por tomar', function () {
    $medir = function (int $cuantos) {
        $e = escenarioDeReparto(porTomar: $cuantos, mios: 1);
        Sanctum::actingAs($e['repartidor']);

        // Calentar: `platform_settings` se cachea por proceso.
        test()->getJson('/v1/domiciliaries/pedidos')->assertOk();

        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });

        test()->getJson('/v1/domiciliaries/pedidos')->assertOk();

        return $n;
    };

    $dos = $medir(2);
    $ocho = $medir(8);

    /*
     * La precarga es por CONSULTA, no por fila, así que ocho pedidos cuestan
     * lo mismo que dos. Es la propiedad que hay que defender: un N+1 futuro
     * —una relación leída dentro del `map`— rompe esta igualdad.
     */
    expect($ocho)->toBe($dos);
});
