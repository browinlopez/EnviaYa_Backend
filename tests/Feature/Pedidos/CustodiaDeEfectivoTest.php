<?php

use App\Models\Operacion\CashDeposit;
use App\Models\Operacion\CashMovement;
use App\Models\Order\OrdersSales;
use App\Models\Rol;
use App\Models\User;
use App\Services\CustodiaDeEfectivo;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Quién tiene el efectivo.
 *
 * Antes de esto, entregar un pedido en efectivo creaba un pago `approved` y
 * dejaba el pedido `paid` —como si el dinero hubiera llegado a la plataforma—
 * mientras seguía en el bolsillo de quien entregó, sin registro. La
 * liquidación remataba el hueco: le pagaba su comisión sin cobrarle lo
 * recaudado.
 *
 * Lo que se fija acá es que el dinero deje rastro desde que se recibe hasta
 * que se consigna.
 */
function domiciliarioConPedido(int $total = 32000, int $metodo = 1): array
{
    Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $repartidor = User::factory()->create(['rol' => 3]);
    $domiId = DB::table('domiciliary')->insertGetId([
        'user_id' => $repartidor->user_id, 'available' => 1,
        'qualification' => 0, 'state' => 1,
    ], 'domiciliary_id');

    $comprador = User::factory()->create(['rol' => 1]);
    $buyerId = DB::table('buyer')->insertGetId([
        'user_id' => $comprador->user_id, 'qualification' => 0,
        'belongs_to_complex' => 0, 'state' => 1,
    ]);

    $businessId = DB::table('business')->insertGetId([
        'name' => 'Tienda', 'qualification' => 0, 'state' => 1,
    ]);

    // `orderssales.methods_id` es clave foránea: sin la fila, el insert falla.
    // El 1 es Efectivo y el 2 una pasarela, según `paymentseeder`.
    foreach ([1 => 'Efectivo', 2 => 'Tarjeta'] as $id => $nombre) {
        DB::table('payment_methods')->insertOrIgnore([
            'methods_id' => $id, 'name' => $nombre, 'state' => 1,
        ]);
    }

    $orderId = DB::table('orderssales')->insertGetId([
        'buyer_id' => $buyerId, 'busines_id' => $businessId,
        'domiciliary_id' => $domiId, 'methods_id' => $metodo,
        'subtotal' => $total - 2000, 'domicilio' => 2000, 'total' => $total,
        'domiciliary_fee' => 500, 'sale_date' => now(),
        'dispatched_at' => now(), 'state' => 3, 'payment_state' => 'pending_cash',
    ], 'orderSales_id');

    return compact('repartidor', 'domiId', 'orderId', 'total');
}

function entregar(array $e): TestResponse
{
    Sanctum::actingAs($e['repartidor']);

    return test()->putJson('/v1/orders/update', [
        'order_id' => $e['orderId'],
        'state' => 4,
        'user_id' => $e['repartidor']->user_id,
    ]);
}

function revisor(): int
{
    return User::factory()->create(['rol' => 3])->user_id;
}

/* ---------------------------------------------------------------------- */

test('entregar en efectivo deja al domiciliario debiendo el total', function () {
    $e = domiciliarioConPedido(32000);
    entregar($e)->assertSuccessful();

    // Recauda el TOTAL, no su comisión: cobra 32.000 y gana 500.
    expect(app(CustodiaDeEfectivo::class)->saldo($e['domiId']))->toBe(32000.0);

    $m = CashMovement::where('order_id', $e['orderId'])->first();
    expect($m)->not->toBeNull()
        ->and($m->type)->toBe(CashMovement::RECAUDO);

    expect((float) OrdersSales::find($e['orderId'])->cash_due)->toBe(32000.0);
});

test('un pago en linea no genera deuda de efectivo', function () {
    // El dinero de una pasarela nunca pasa por las manos del repartidor.
    $e = domiciliarioConPedido(32000, metodo: 2);
    entregar($e)->assertSuccessful();

    expect(app(CustodiaDeEfectivo::class)->saldo($e['domiId']))->toBe(0.0)
        ->and(CashMovement::where('order_id', $e['orderId'])->exists())->toBeFalse();
});

test('reintentar la entrega no duplica la deuda', function () {
    // `updateStatus` se reintenta ante un fallo de red. Sin la guarda, el
    // segundo intento le cobraría el pedido dos veces al domiciliario.
    $e = domiciliarioConPedido(32000);
    entregar($e)->assertSuccessful();
    entregar($e);

    expect(app(CustodiaDeEfectivo::class)->saldo($e['domiId']))->toBe(32000.0)
        ->and(CashMovement::where('order_id', $e['orderId'])->count())->toBe(1);
});

test('declarar una consignacion no baja el saldo', function () {
    // Declarar no es entregar. Si bajara al declararlo, saldar la deuda sería
    // cuestión de escribir una referencia inventada.
    $e = domiciliarioConPedido(32000);
    entregar($e)->assertSuccessful();

    $custodia = app(CustodiaDeEfectivo::class);
    $custodia->declararDeposito($e['domiId'], ['amount' => 32000, 'reference' => 'ABC123']);

    expect($custodia->saldo($e['domiId']))->toBe(32000.0);
});

test('confirmar la consignacion si lo baja', function () {
    $e = domiciliarioConPedido(32000);
    entregar($e)->assertSuccessful();

    $custodia = app(CustodiaDeEfectivo::class);
    $deposito = $custodia->declararDeposito($e['domiId'], ['amount' => 32000, 'reference' => 'ABC123']);

    $custodia->confirmarDeposito($deposito, revisor());

    expect($custodia->saldo($e['domiId']))->toBe(0.0)
        ->and($deposito->fresh()->state)->toBe(CashDeposit::CONFIRMADA)
        ->and($deposito->fresh()->confirmed_at)->not->toBeNull();
});

test('una consignacion parcial deja el resto debiendo', function () {
    $e = domiciliarioConPedido(32000);
    entregar($e)->assertSuccessful();

    $custodia = app(CustodiaDeEfectivo::class);
    $deposito = $custodia->declararDeposito($e['domiId'], ['amount' => 20000]);
    $custodia->confirmarDeposito($deposito, revisor());

    expect($custodia->saldo($e['domiId']))->toBe(12000.0);
});

test('no se confirma dos veces el mismo deposito', function () {
    // Sin esta guarda, confirmar dos veces borraría una deuda que sigue viva.
    $e = domiciliarioConPedido(32000);
    entregar($e)->assertSuccessful();

    $custodia = app(CustodiaDeEfectivo::class);
    $deposito = $custodia->declararDeposito($e['domiId'], ['amount' => 32000]);
    $quien = revisor();

    $custodia->confirmarDeposito($deposito, $quien);

    expect(fn () => $custodia->confirmarDeposito($deposito->fresh(), $quien))
        ->toThrow(RuntimeException::class);

    expect($custodia->saldo($e['domiId']))->toBe(0.0);
});

test('un deposito rechazado no toca el saldo', function () {
    $e = domiciliarioConPedido(32000);
    entregar($e)->assertSuccessful();

    $custodia = app(CustodiaDeEfectivo::class);
    $deposito = $custodia->declararDeposito($e['domiId'], ['amount' => 32000]);
    $custodia->rechazarDeposito($deposito, revisor(), 'La referencia no aparece');

    expect($custodia->saldo($e['domiId']))->toBe(32000.0)
        ->and($deposito->fresh()->state)->toBe(CashDeposit::RECHAZADA);
});

test('el saldo de dos domiciliarios no se mezcla', function () {
    $a = domiciliarioConPedido(30000);
    $b = domiciliarioConPedido(15000);

    entregar($a)->assertSuccessful();
    entregar($b)->assertSuccessful();

    $custodia = app(CustodiaDeEfectivo::class);

    expect($custodia->saldo($a['domiId']))->toBe(30000.0)
        ->and($custodia->saldo($b['domiId']))->toBe(15000.0);
});

/* ------------------------------------------- lo que el barrido encontro -- */

/**
 * ESTA SALIO DE UN BARRIDO DE SABOTAJE, el 14 de noviembre de 2026.
 *
 * Poniendo `CashMovement::RECAUDO = 'ajuste'` --o sea haciendo que lo cobrado
 * al cliente y un ajuste a mano sean la misma cosa-- pasaban las 1361 pruebas,
 * con ONCE cubriendo esta custodia.
 *
 * POR QUE NO LO VIO NINGUNA: todas escriben y leen por la misma constante. El
 * apunte usa `RECAUDO`, la guarda de idempotencia busca `RECAUDO` y el saldo
 * suma `RECAUDO`; si el valor cambia, cambian los tres a la vez y las cuentas
 * siguen cuadrando entre ellas. Lo que NO cuadra es con los ajustes, y eso no
 * lo miraba nadie.
 *
 * EL DANO, que es plata: `pendientesConLaTienda()` --lo que el panel del
 * tendero le dice que su domiciliario le debe-- filtra por `type = RECAUDO`.
 * Con los dos tipos valiendo lo mismo, un ajuste hecho a mano (perdonar un
 * descuadre, corregir un error de digitacion) se cuenta como plata recaudada y
 * se le cobra al domiciliario.
 */
test('un ajuste a mano NO es plata recaudada', function () {
    $a = domiciliarioConPedido(30000);

    entregar($a)->assertSuccessful();

    /*
     * Un ajuste: lo que se usa para corregir o perdonar un descuadre. Entra en
     * la misma tabla que el recaudo y se distingue SOLO por el tipo.
     */
    CashMovement::create([
        'domiciliary_id' => $a['domiId'],
        'type' => CashMovement::AJUSTE,
        'amount' => 500000.0,
        'notes' => 'descuadre perdonado',
    ]);

    $negocio = (int) DB::table('orderssales')
        ->where('orderSales_id', $a['orderId'])
        ->value('busines_id');

    /*
     * CONTROL, ANTES DE MIRAR NADA. Con el negocio equivocado --o con un 0,
     * que es lo que daria una clave mal escrita-- `pendientesConLaTienda`
     * devuelve vacio y «el ajuste no aparece como deuda» seria verdad sin
     * significar nada. Primero se comprueba que el recaudo SI se ve.
     */
    expect($negocio)->toBeGreaterThan(0);

    $pendientes = app(CustodiaDeEfectivo::class)
        ->pendientesConLaTienda($negocio, [$a['domiId']]);

    expect(array_sum($pendientes))->toBe(30000.0);

    /*
     * Y AHORA LO QUE IMPORTA: el medio millon del ajuste no esta ahi. Con los
     * dos tipos valiendo lo mismo, esta suma daria 530.000 -- y es lo que el
     * panel le cobraria al domiciliario.
     */
    expect(array_sum($pendientes))->not->toBeGreaterThan(30000.0);
});

test('los tres tipos de movimiento son distintos entre si', function () {
    /*
     * CON LITERALES Y NO CON LAS CONSTANTES. El tipo se escribe en la columna
     * `cash_movements.type` de filas que ya existen, asi que su valor es un
     * dato guardado y no un detalle interno: cambiarlo deja los movimientos
     * viejos sin clasificar.
     *
     * Y la prueba es que sean DISTINTOS, porque es lo unico que el resto del
     * codigo necesita de ellos: todo el servicio escribe y lee por la misma
     * constante, asi que lo que nunca se comprobaba era que no colisionaran.
     */
    expect(CashMovement::RECAUDO)->toBe('recaudo');
    expect(CashMovement::CONSIGNACION)->toBe('consignacion');
    expect(CashMovement::AJUSTE)->toBe('ajuste');

    $tipos = [CashMovement::RECAUDO, CashMovement::CONSIGNACION, CashMovement::AJUSTE];
    expect(count(array_unique($tipos)))->toBe(3);
});
