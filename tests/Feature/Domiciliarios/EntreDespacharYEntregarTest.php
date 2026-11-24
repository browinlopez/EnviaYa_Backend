<?php

use App\Models\Notification;
use App\Models\Order\OrdersSales;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Lo que pasa entre despachar y entregar: el aviso de «pedido para tomar», el
 * PIN, «Llegué», con qué pagó, soltar un pedido y la novedad con su
 * devolución. Ver `NovedadesDeEntrega` y `PinDeEntrega`.
 */
function escenaDeReparto(int $estado = 2, int $total = 20000): array
{
    foreach ([1 => 'comprador', 2 => 'tendero', 3 => 'domiciliario'] as $id => $n) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $n, 'guard_name' => 'web']);
    }

    $tendero = User::factory()->create(['rol' => 2]);
    $ownerId = DB::table('owner')->insertGetId(['user_id' => $tendero->user_id, 'state' => 1], 'owner_id');
    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId([
        'name' => 'Tienda del barrio', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');
    DB::table('owner_busines')->insert(['owner_id' => $ownerId, 'busines_id' => $negocio, 'state' => 1]);

    $domis = [];
    foreach (['disponible' => 1, 'otro' => 1, 'apagado' => 0] as $clave => $disponible) {
        $u = User::factory()->create(['rol' => 3]);
        $id = DB::table('domiciliary')->insertGetId([
            'user_id' => $u->user_id, 'available' => $disponible, 'qualification' => 0, 'state' => 1,
        ], 'domiciliary_id');
        DB::table('business_domiciliary')->insert([
            'busines_id' => $negocio, 'domiciliary_id' => $id, 'state' => 1,
        ]);
        $domis[$clave] = ['user' => $u, 'id' => $id];
    }

    $comprador = User::factory()->create(['rol' => 1]);
    $buyer = DB::table('buyer')->insertGetId(['user_id' => $comprador->user_id, 'qualification' => 0, 'state' => 1]);
    DB::table('payment_methods')->insertOrIgnore(['methods_id' => 1, 'name' => 'Efectivo', 'state' => 1]);

    $pedido = DB::table('orderssales')->insertGetId([
        'buyer_id' => $buyer, 'busines_id' => $negocio, 'methods_id' => 1,
        'subtotal' => $total - 3000, 'domicilio' => 3000, 'total' => $total,
        'domiciliary_fee' => 2500, 'sale_date' => now(), 'state' => $estado,
        'payment_state' => 'pending_cash',
    ], 'orderSales_id');

    return compact('tendero', 'negocio', 'domis', 'comprador', 'pedido');
}

function despacharA(array $e, string $quien = 'disponible'): void
{
    Sanctum::actingAs($e['domis'][$quien]['user']);
    test()->putJson('/v1/orders/update', [
        'order_id' => $e['pedido'], 'state' => 3, 'user_id' => $e['domis'][$quien]['user']->user_id,
    ])->assertSuccessful();
}

function entregarCon(array $e, array $extra = [], string $quien = 'disponible')
{
    Sanctum::actingAs($e['domis'][$quien]['user']);

    return test()->putJson('/v1/orders/update', [
        'order_id' => $e['pedido'], 'state' => 4, 'user_id' => $e['domis'][$quien]['user']->user_id,
    ] + $extra);
}

function avisosDe(User $u, string $tipo): int
{
    return Notification::where('user_id', $u->user_id)->where('tipo', $tipo)->count();
}

/* --------------------------- pedido para tomar --------------------------- */

test('al aceptar un pedido les llega el aviso a los domiciliarios disponibles del negocio', function () {
    $e = escenaDeReparto(1);
    Sanctum::actingAs($e['tendero']);

    $this->putJson('/v1/negocio/pedidos/estado', ['order_id' => $e['pedido'], 'state' => 2])->assertSuccessful();

    expect(avisosDe($e['domis']['disponible']['user'], 'pedido_para_tomar'))->toBe(1)
        ->and(avisosDe($e['domis']['otro']['user'], 'pedido_para_tomar'))->toBe(1)
        // «Terminar por hoy» es no querer avisos.
        ->and(avisosDe($e['domis']['apagado']['user'], 'pedido_para_tomar'))->toBe(0);

    $aviso = Notification::where('user_id', $e['domis']['disponible']['user']->user_id)->first();
    expect($aviso->datos['order_id'])->toBe($e['pedido']);
});

test('el domiciliario de otro negocio no se entera', function () {
    $e = escenaDeReparto(1);
    $ajeno = User::factory()->create(['rol' => 3]);
    DB::table('domiciliary')->insert(['user_id' => $ajeno->user_id, 'available' => 1, 'qualification' => 0, 'state' => 1]);

    Sanctum::actingAs($e['tendero']);
    $this->putJson('/v1/negocio/pedidos/estado', ['order_id' => $e['pedido'], 'state' => 2])->assertSuccessful();

    expect(avisosDe($ajeno, 'pedido_para_tomar'))->toBe(0);
});

/* ---------------------------------- PIN ---------------------------------- */

test('despachar crea un PIN de 3 digitos que no sale en el JSON del pedido', function () {
    $e = escenaDeReparto();
    despacharA($e);

    $pedido = OrdersSales::find($e['pedido']);
    expect($pedido->pin_entrega)->toMatch('/^\d{3}$/')
        ->and($pedido->toArray())->not->toHaveKey('pin_entrega');
});

test('el comprador ve su PIN y el domiciliario no puede pedirlo', function () {
    $e = escenaDeReparto();
    despacharA($e);
    $pin = OrdersSales::find($e['pedido'])->pin_entrega;

    Sanctum::actingAs($e['comprador']);
    $this->getJson("/v1/orders/{$e['pedido']}/pin")->assertOk()->assertJsonPath('data.pin', $pin);

    Sanctum::actingAs($e['domis']['disponible']['user']);
    $this->getJson("/v1/orders/{$e['pedido']}/pin")->assertNotFound();
});

test('el negocio tambien ve el PIN', function () {
    $e = escenaDeReparto();
    despacharA($e);

    Sanctum::actingAs($e['tendero']);
    $this->getJson("/v1/negocio/pedidos/{$e['pedido']}/pin")
        ->assertOk()
        ->assertJsonPath('data.pin', OrdersSales::find($e['pedido'])->pin_entrega);
});

test('el domiciliario no entrega sin PIN, ni con uno equivocado', function () {
    $e = escenaDeReparto();
    despacharA($e);
    $pin = OrdersSales::find($e['pedido'])->pin_entrega;
    $malo = str_pad((string) (((int) $pin + 1) % 1000), 3, '0', STR_PAD_LEFT);

    entregarCon($e)->assertStatus(422)->assertJsonPath('reason', 'pin_requerido');
    entregarCon($e, ['pin' => $malo])->assertStatus(422)->assertJsonPath('intentos_restantes', 4);

    expect((int) OrdersSales::find($e['pedido'])->state)->toBe(3);

    entregarCon($e, ['pin' => $pin])->assertSuccessful();
    expect((int) OrdersSales::find($e['pedido'])->state)->toBe(4);
});

test('a los cinco fallos el PIN se bloquea, aunque luego acierte', function () {
    $e = escenaDeReparto();
    despacharA($e);
    $pin = OrdersSales::find($e['pedido'])->pin_entrega;
    $malo = str_pad((string) (((int) $pin + 1) % 1000), 3, '0', STR_PAD_LEFT);

    foreach (range(1, 5) as $_) {
        entregarCon($e, ['pin' => $malo]);
    }

    entregarCon($e, ['pin' => $pin])->assertStatus(423)->assertJsonPath('reason', 'pin_bloqueado');
});

test('un pedido despachado antes del PIN se entrega sin PIN', function () {
    $e = escenaDeReparto(3);
    DB::table('orderssales')->where('orderSales_id', $e['pedido'])->update(['domiciliary_id' => $e['domis']['disponible']['id']]);

    entregarCon($e)->assertSuccessful();
});

/* ------------------------------ paga con ------------------------------ */

test('guarda con que pago y calcula el vuelto en el servidor', function () {
    $e = escenaDeReparto(2, 17500);
    despacharA($e);

    entregarCon($e, ['pin' => OrdersSales::find($e['pedido'])->pin_entrega, 'paga_con' => 20000])->assertSuccessful();

    $p = OrdersSales::find($e['pedido']);
    expect((float) $p->cash_received)->toBe(20000.0)->and((float) $p->change_given)->toBe(2500.0);
});

test('un billete que no alcanza se rechaza', function () {
    $e = escenaDeReparto(2, 17500);
    despacharA($e);

    entregarCon($e, ['pin' => OrdersSales::find($e['pedido'])->pin_entrega, 'paga_con' => 10000])
        ->assertStatus(422)->assertJsonPath('reason', 'paga_con_insuficiente');
});

/* -------------------------------- Llegué -------------------------------- */

test('Llegue avisa al comprador una sola vez', function () {
    $e = escenaDeReparto();
    despacharA($e);

    $this->postJson("/v1/domiciliaries/orders/{$e['pedido']}/llegue")->assertOk();
    $this->postJson("/v1/domiciliaries/orders/{$e['pedido']}/llegue")->assertOk();

    expect(avisosDe($e['comprador'], 'domiciliario_llego'))->toBe(1)
        ->and(OrdersSales::find($e['pedido'])->llego_at)->not->toBeNull();
});

test('Llegue sobre un pedido ajeno es 404', function () {
    $e = escenaDeReparto();
    despacharA($e);

    Sanctum::actingAs($e['domis']['otro']['user']);
    $this->postJson("/v1/domiciliaries/orders/{$e['pedido']}/llegue")->assertNotFound();
});

/* -------------------------------- soltar -------------------------------- */

test('soltar devuelve el pedido a Para tomar y avisa al negocio y a los demas', function () {
    $e = escenaDeReparto();
    despacharA($e);

    $this->postJson("/v1/domiciliaries/orders/{$e['pedido']}/soltar", ['motivo' => 'moto'])->assertOk();

    $p = OrdersSales::find($e['pedido']);
    expect((int) $p->state)->toBe(2)
        ->and($p->domiciliary_id)->toBeNull()
        ->and($p->pin_entrega)->toBeNull()
        ->and(avisosDe($e['tendero'], 'pedido_soltado'))->toBe(1)
        ->and(avisosDe($e['domis']['otro']['user'], 'pedido_para_tomar'))->toBe(1)
        // A quien lo soltó no: es el pedido que acaba de dejar.
        ->and(avisosDe($e['domis']['disponible']['user'], 'pedido_para_tomar'))->toBe(0);
});

test('otra cosa exige contar que paso', function () {
    $e = escenaDeReparto();
    despacharA($e);

    $this->postJson("/v1/domiciliaries/orders/{$e['pedido']}/soltar", ['motivo' => 'otro'])
        ->assertStatus(422)->assertJsonPath('message', 'Cuéntanos qué pasó.');
});

test('un motivo inventado se rechaza', function () {
    $e = escenaDeReparto();
    despacharA($e);

    $this->postJson("/v1/domiciliaries/orders/{$e['pedido']}/soltar", ['motivo' => 'pereza'])->assertStatus(422);
});

/* ------------------------- novedad y devolución ------------------------- */

test('una novedad pasa el pedido a devolviendo y avisa al negocio y al comprador', function () {
    $e = escenaDeReparto();
    despacharA($e);

    $this->postJson("/v1/domiciliaries/orders/{$e['pedido']}/novedad", [
        'motivo' => 'accidente', 'latitude' => 10.98, 'longitude' => -74.8,
    ])->assertCreated()->assertJsonPath('data.state', OrdersSales::DEVOLVIENDO);

    expect((int) OrdersSales::find($e['pedido'])->state)->toBe(OrdersSales::DEVOLVIENDO)
        ->and(avisosDe($e['tendero'], 'pedido_devolviendo'))->toBe(1)
        ->and(avisosDe($e['comprador'], 'pedido_no_entregado'))->toBe(1);

    $novedad = DB::table('novedades_de_entrega')->where('order_id', $e['pedido'])->first();
    expect($novedad->motivo)->toBe('accidente')
        ->and((float) $novedad->domicilio_reconocido)->toBe(2500.0);
});

test('un pedido devolviendo ya no se puede entregar', function () {
    $e = escenaDeReparto();
    despacharA($e);
    $pin = OrdersSales::find($e['pedido'])->pin_entrega;
    $this->postJson("/v1/domiciliaries/orders/{$e['pedido']}/novedad", ['motivo' => 'no_contesta'])->assertCreated();

    entregarCon($e, ['pin' => $pin])->assertStatus(400);
    expect((int) OrdersSales::find($e['pedido'])->state)->toBe(OrdersSales::DEVOLVIENDO);
});

test('el negocio recibe la devolucion y el pedido queda cancelado', function () {
    $e = escenaDeReparto();
    despacharA($e);
    $this->postJson("/v1/domiciliaries/orders/{$e['pedido']}/novedad", ['motivo' => 'direccion_incorrecta'])->assertCreated();

    Sanctum::actingAs($e['tendero']);
    $this->postJson("/v1/negocio/pedidos/{$e['pedido']}/devolucion")->assertOk();

    expect((int) OrdersSales::find($e['pedido'])->state)->toBe(OrdersSales::CANCELADO)
        ->and(DB::table('novedades_de_entrega')->where('order_id', $e['pedido'])->value('devolucion_recibida_en'))->not->toBeNull();

    // Dos veces no: ya no está en devolución.
    $this->postJson("/v1/negocio/pedidos/{$e['pedido']}/devolucion")->assertStatus(422);
});

test('otro negocio no puede recibir la devolucion', function () {
    $e = escenaDeReparto();
    despacharA($e);
    $this->postJson("/v1/domiciliaries/orders/{$e['pedido']}/novedad", ['motivo' => 'no_contesta'])->assertCreated();

    $otro = escenaDeReparto();
    Sanctum::actingAs($otro['tendero']);
    $this->postJson("/v1/negocio/pedidos/{$e['pedido']}/devolucion")->assertNotFound();
});

test('las listas de motivos salen del servidor', function () {
    $e = escenaDeReparto();
    Sanctum::actingAs($e['domis']['disponible']['user']);

    $this->getJson('/v1/domiciliaries/motivos')
        ->assertOk()
        ->assertJsonPath('data.novedad.4.clave', 'accidente')
        ->assertJsonPath('data.con_nota.0', 'otro');
});
