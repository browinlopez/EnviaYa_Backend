<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * LA FICHA DE UN DOMICILIARIO, VISTA POR LA TIENDA.
 *
 * Un domiciliario reparte para varias tiendas, y de ahi salen las dos cosas
 * que se prueban aca —porque las dos se rompen en silencio—:
 *
 *  1. QUIEN PUEDE PEDIRLA. Solo la tienda a la que esta vinculado. La ficha
 *     lleva telefono, correo y la situacion de sus documentos: servirsela a
 *     cualquiera que escriba un numero en la ruta es repartir datos de una
 *     persona a quien no tiene nada que ver con ella.
 *
 *  2. QUE SE CUENTA. Entregas, plata y pedidos encima, SOLO los de la tienda
 *     que pregunta. Sin el recorte, el tendero veria los nombres de los
 *     compradores de la tienda de enfrente —y una cifra de entregas que no es
 *     la que le hizo a el—.
 *
 * Y una tercera, de lectura: los papeles que NO tiene tienen que salir, porque
 * el que falta es justamente el que se viene a mirar.
 */
function tiendaConDomiciliario(): array
{
    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);

    $tendero = User::factory()->create(['rol' => 2]);
    $ownerId = DB::table('owner')->insertGetId(
        ['user_id' => $tendero->user_id, 'state' => 1], 'owner_id',
    );
    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');

    $mia = DB::table('business')->insertGetId([
        'name' => 'Tienda del Buho', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');
    $otra = DB::table('business')->insertGetId([
        'name' => 'Tienda La Esquina', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');

    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $mia, 'state' => 1,
    ]);

    DB::table('payment_methods')->insertOrIgnore([
        'methods_id' => 1, 'name' => 'Efectivo', 'state' => 1,
    ]);

    $repartidor = User::factory()->create(['rol' => 3, 'name' => 'Carlos Mendoza']);
    $domiId = DB::table('domiciliary')->insertGetId([
        'user_id' => $repartidor->user_id, 'available' => 1,
        'qualification' => 0, 'state' => 1,
    ], 'domiciliary_id');

    // Reparte para las dos: es el caso que hace falta para probar el recorte.
    DB::table('business_domiciliary')->insert([
        ['busines_id' => $mia,  'domiciliary_id' => $domiId, 'state' => 1],
        ['busines_id' => $otra, 'domiciliary_id' => $domiId, 'state' => 1],
    ]);

    $comprador = User::factory()->create(['rol' => 1, 'name' => 'Camilo T.']);
    $buyerId = DB::table('buyer')->insertGetId([
        'user_id' => $comprador->user_id, 'qualification' => 0, 'state' => 1,
    ]);

    return compact('tendero', 'mia', 'otra', 'domiId', 'buyerId');
}

function pedidoDelRepartidor(array $t, int $negocio, int $estado, float $fee = 0): int
{
    return DB::table('orderssales')->insertGetId([
        'buyer_id' => $t['buyerId'], 'busines_id' => $negocio,
        'domiciliary_id' => $t['domiId'],
        'subtotal' => 20000, 'domicilio' => 4000, 'total' => 24000,
        'sale_date' => now()->subHours(2),
        'dispatched_at' => now()->subHours(1),
        'delivery_date' => $estado === 4 ? now()->subMinutes(30) : null,
        'state' => $estado, 'payment_state' => 'paid', 'methods_id' => 1,
        'domiciliary_fee' => $fee,
    ], 'orderSales_id');
}

test('la ficha de un domiciliario que no reparte para ti no existe', function () {
    $t = tiendaConDomiciliario();

    // Uno vinculado SOLO a la otra tienda.
    $ajeno = User::factory()->create(['rol' => 3]);
    $ajenoId = DB::table('domiciliary')->insertGetId([
        'user_id' => $ajeno->user_id, 'available' => 1, 'qualification' => 0, 'state' => 1,
    ], 'domiciliary_id');
    DB::table('business_domiciliary')->insert([
        'busines_id' => $t['otra'], 'domiciliary_id' => $ajenoId, 'state' => 1,
    ]);

    Sanctum::actingAs($t['tendero']);

    $this->getJson("/v1/negocio/domiciliarios/{$ajenoId}")->assertNotFound();
    $this->getJson("/v1/negocio/domiciliarios/{$t['domiId']}")->assertOk();
});

test('entregas y plata se cuentan solo sobre los pedidos de tu tienda', function () {
    $t = tiendaConDomiciliario();

    pedidoDelRepartidor($t, $t['mia'],  4, 3000);
    pedidoDelRepartidor($t, $t['mia'],  4, 3000);
    pedidoDelRepartidor($t, $t['otra'], 4, 9000);   // de la tienda de enfrente: no cuenta

    Sanctum::actingAs($t['tendero']);
    $r = $this->getJson("/v1/negocio/domiciliarios/{$t['domiId']}")->assertOk();

    expect($r->json('actividad.entregas'))->toBe(2)
        ->and($r->json('actividad.ganado'))->toEqual(6000);
});

test('lo que lleva encima son tus pedidos, no los del vecino', function () {
    $t = tiendaConDomiciliario();

    pedidoDelRepartidor($t, $t['mia'],  3);
    pedidoDelRepartidor($t, $t['otra'], 3);   // tambien lo lleva encima, pero no es tuyo

    Sanctum::actingAs($t['tendero']);
    $r = $this->getJson("/v1/negocio/domiciliarios/{$t['domiId']}")->assertOk();

    /* Que la lista traiga uno solo no basta: el contador de arriba tiene que
       decir lo mismo. Si uno sale de la lista recortada y el otro del total
       real, la ficha se contradice sola. */
    expect($r->json('encima'))->toHaveCount(1)
        ->and($r->json('domiciliario.active_orders'))->toBe(1);
});

test('los otros negocios salen por nombre y marcados como ajenos', function () {
    $t = tiendaConDomiciliario();

    Sanctum::actingAs($t['tendero']);
    $r = $this->getJson("/v1/negocio/domiciliarios/{$t['domiId']}")->assertOk();

    $mios = collect($r->json('negocios'))->where('es_tuyo', true)->pluck('name');

    expect($r->json('negocios'))->toHaveCount(2)
        ->and($mios->all())->toBe(['Tienda del Buho']);
});

test('los papeles que no tiene salen como falta, no se omiten', function () {
    $t = tiendaConDomiciliario();

    Sanctum::actingAs($t['tendero']);
    $r = $this->getJson("/v1/negocio/domiciliarios/{$t['domiId']}")->assertOk();

    $porTipo = collect($r->json('documentos'))->keyBy('tipo');

    expect($porTipo->get('licencia')['situacion'])->toBe('falta')
        ->and($porTipo->get('soat')['situacion'])->toBe('falta')
        ->and($porTipo->get('cedula')['situacion'])->toBe('falta');
});

test('un SOAT vencido se ve vencido', function () {
    $t = tiendaConDomiciliario();

    DB::table('domiciliary_documents')->insert([
        'domiciliary_id' => $t['domiId'], 'type' => 'soat',
        'expires_at' => now()->subDays(3)->toDateString(), 'state' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Sanctum::actingAs($t['tendero']);
    $r = $this->getJson("/v1/negocio/domiciliarios/{$t['domiId']}")->assertOk();

    $soat = collect($r->json('documentos'))->firstWhere('tipo', 'soat');

    expect($soat['situacion'])->toBe('vencido');
});

test('la ficha no sirve el archivo del documento, solo su situacion', function () {
    // La tienda tiene que saber si la licencia esta al dia; la foto de la
    // cedula de un tercero no es asunto suyo.
    $t = tiendaConDomiciliario();

    DB::table('domiciliary_documents')->insert([
        'domiciliary_id' => $t['domiId'], 'type' => 'licencia',
        'number' => '1234567890', 'media_id' => null,
        'expires_at' => now()->addYear()->toDateString(), 'state' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Sanctum::actingAs($t['tendero']);
    $r = $this->getJson("/v1/negocio/domiciliarios/{$t['domiId']}")->assertOk();

    $licencia = collect($r->json('documentos'))->firstWhere('tipo', 'licencia');

    expect($licencia['situacion'])->toBe('vigente')
        ->and($licencia)->not->toHaveKey('media_id')
        ->and($licencia)->not->toHaveKey('number');
});

test('la vista de detalle trae solo tus pedidos, tus pagos y su historial', function () {
    $t = tiendaConDomiciliario();

    $mio = pedidoDelRepartidor($t, $t['mia'], 4, 3000);
    pedidoDelRepartidor($t, $t['mia'], 5);          // cancelado contigo
    pedidoDelRepartidor($t, $t['otra'], 4, 9000);   // del vecino: no sale en nada

    Sanctum::actingAs($t['tendero']);
    $r = $this->getJson("/v1/negocio/domiciliarios/{$t['domiId']}")->assertOk();

    expect($r->json('pedidos'))->toHaveCount(2)
        ->and($r->json('pagos.pagado'))->toEqual(3000)
        ->and($r->json('por_dia'))->toHaveCount(7);

    $textos = collect($r->json('historial'))->pluck('texto');
    expect($textos)->toContain("Entregó el pedido #{$mio}")
        ->and($textos->filter(fn ($x) => str_contains($x, 'cancelado')))->toHaveCount(1)
        ->and($textos->filter(fn ($x) => str_contains($x, '9.000')))->toBeEmpty();
});
