<?php

use App\Events\OrderCreated;
use App\Models\Order\OrdersSales;
use App\Models\Rol;
use App\Models\User;
use App\Services\ConfirmacionDePago;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Un pago sin confirmar NO es un pedido.
 *
 * El pedido se guarda antes de cobrar —la pasarela necesita una referencia, y
 * si el dinero llega a moverse tiene que existir dónde apuntarlo—. Pero hasta
 * que el cobro se confirma no debe existir para nadie.
 *
 * Antes no era así: un pago rechazado dejaba el pedido en la lista de la
 * tienda, con su aviso en vivo, y el tendero podía ponerse a preparar comida
 * que nadie pagó. Cada reintento dejaba otro.
 */
function pedidoEsperandoPago(float $total = 4500): OrdersSales
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $user = User::factory()->create(['rol' => 1]);
    $buyerId = DB::table('buyer')->insertGetId(['user_id' => $user->user_id, 'state' => 1]);

    // El negocio se crea acá: la tabla tiene clave foránea y en memoria no hay
    // datos de arranque.
    $businessId = DB::table('business')->insertGetId([
        'name' => 'Tienda de Prueba', 'qualification' => 0, 'state' => 1,
    ]);

    $id = DB::table('orderssales')->insertGetId([
        'buyer_id' => $buyerId, 'busines_id' => $businessId,
        'total' => $total, 'subtotal' => $total - 2000, 'domicilio' => 2000,
        'sale_date' => now(), 'state' => 1,
        'payment_state' => OrdersSales::ESPERANDO_PAGO,
    ], 'orderSales_id');

    return OrdersSales::find($id);
}

/* ---------------------------------------------------------------------- */

test('el que espera pago queda fuera de los listados', function () {
    $esperando = pedidoEsperandoPago();

    $visibles = OrdersSales::confirmados()->pluck('orderSales_id');

    expect($visibles)->not->toContain($esperando->orderSales_id);
});

test('el rechazado tampoco aparece', function () {
    // El webhook lo marca así cuando la pasarela lo rechaza definitivamente.
    $rechazado = pedidoEsperandoPago();
    $rechazado->update(['payment_state' => OrdersSales::PAGO_RECHAZADO]);

    expect(OrdersSales::confirmados()->pluck('orderSales_id'))
        ->not->toContain($rechazado->orderSales_id);
});

test('el de pago contra entrega sí aparece, aunque esté pendiente', function () {
    /*
     * Nace pendiente por definición y es un pedido perfectamente válido: hay
     * que preparlo y llevarlo. Una regla del tipo "solo los pagados" habría
     * escondido todas las ventas en efectivo.
     */
    $efectivo = pedidoEsperandoPago();
    $efectivo->update(['payment_state' => 'pending_cash']);

    expect(OrdersSales::confirmados()->pluck('orderSales_id'))
        ->toContain($efectivo->orderSales_id);
});

test('los históricos con estados antiguos siguen visibles', function () {
    // En la base hay `approved` y `pending` de antes de que se unificaran los
    // nombres. Esconderlos habría vaciado el historial de las tiendas.
    foreach (['approved', 'pending'] as $estado) {
        $viejo = pedidoEsperandoPago();
        $viejo->update(['payment_state' => $estado]);

        expect(OrdersSales::confirmados()->pluck('orderSales_id'))
            ->toContain($viejo->orderSales_id);
    }
});

/* ------------------------ AL CONFIRMARSE EL PAGO ----------------------- */

test('al confirmarse, el pedido aparece y se anuncia a la tienda', function () {
    Event::fake([OrderCreated::class]);

    $order = pedidoEsperandoPago();

    expect(ConfirmacionDePago::confirmar($order))->toBeTrue()
        ->and($order->fresh()->payment_state)->toBe('paid');

    expect(OrdersSales::confirmados()->pluck('orderSales_id'))
        ->toContain($order->orderSales_id);

    // El aviso va ACÁ y no al crearse: es el momento en que hay algo que
    // preparar de verdad.
    Event::assertDispatched(OrderCreated::class);
});

test('un pedido ya visible no se anuncia dos veces', function () {
    /*
     * El de efectivo se anuncia al crearse. Si al cobrarlo en la puerta se
     * volviera a emitir, la tienda lo vería aparecer por segunda vez.
     */
    Event::fake([OrderCreated::class]);

    $efectivo = pedidoEsperandoPago();
    $efectivo->update(['payment_state' => 'pending_cash']);

    expect(ConfirmacionDePago::confirmar($efectivo))->toBeFalse();

    Event::assertNotDispatched(OrderCreated::class);
});
