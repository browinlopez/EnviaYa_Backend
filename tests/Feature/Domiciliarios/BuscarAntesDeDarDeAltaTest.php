<?php

use App\Models\Domiciliary;
use App\Models\Order\OrdersSales;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * BUSCAR A ALGUIEN ANTES DE CREARLE OTRA CUENTA.
 *
 * La pantalla de alta promete una cosa concreta: «vincúlalo sin crearle otra
 * cuenta». Si la búsqueda no encuentra a quien sí existe, esa promesa se
 * rompe en silencio y hacia el lado malo: el tendero lee «No hay nadie con
 * ese dato. Créalo abajo», lo crea, y a partir de ahí hay DOS fichas de la
 * misma persona, cada una con sus papeles, su efectivo y sus entregas.
 *
 * El teléfono en la base está de las dos maneras --«3002464977» y
 * «303 632 7859»-- porque lo escriben personas distintas en momentos
 * distintos. Nadie lo teclea con espacios al buscarlo.
 */
beforeEach(function () {
    /* Los roles son una tabla con clave ajena: sin ellos no nace ni un `user`. */
    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);
    Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);

    $this->tendero = User::factory()->create(['rol' => 2]);

    $owner = DB::table('owner')->insertGetId([
        'user_id' => $this->tendero->user_id,
        'document_number' => '900900900',
        'state' => 1,
    ]);

    $this->negocio = DB::table('business')->insertGetId([
        'name' => 'Tienda de la prueba',
        'state' => 1,
    ]);

    DB::table('owner_busines')->insert([
        'owner_id' => $owner,
        'busines_id' => $this->negocio,
        'state' => 1,
    ]);

    Sanctum::actingAs($this->tendero);
});

/** Deja un domiciliario con el teléfono escrito tal cual se pide. */
function domiciliarioConTelefono(string $telefono): Domiciliary
{
    $u = User::factory()->create(['rol' => 3, 'phone' => $telefono]);

    return Domiciliary::create([
        'user_id' => $u->user_id,
        'document' => '1098765432',
        'available' => 1,
        'state' => 1,
    ]);
}

it('encuentra a quien tiene el telefono con espacios cuando se busca seguido', function () {
    $d = domiciliarioConTelefono('303 632 7859');

    $r = $this->getJson('/v1/negocio/domiciliarios/buscar?q=3036327859');

    $r->assertOk();
    expect($r->json('data.domiciliary_id'))->toBe($d->domiciliary_id);
})->group('alta-de-domiciliario');

it('y al reves: guardado seguido, buscado con espacios', function () {
    // Pasa de verdad: se copia y se pega desde WhatsApp.
    $d = domiciliarioConTelefono('3002464977');

    $r = $this->getJson('/v1/negocio/domiciliarios/buscar?q=300 246 4977');

    expect($r->json('data.domiciliary_id'))->toBe($d->domiciliary_id);
})->group('alta-de-domiciliario');

it('tambien con guiones y con el indicativo', function () {
    $d = domiciliarioConTelefono('300-246-4977');

    expect($this->getJson('/v1/negocio/domiciliarios/buscar?q=3002464977')
        ->json('data.domiciliary_id'))->toBe($d->domiciliary_id);
})->group('alta-de-domiciliario');

it('no confunde a dos personas por parecerse el numero', function () {
    domiciliarioConTelefono('300 246 4977');

    $r = $this->getJson('/v1/negocio/domiciliarios/buscar?q=3002464000');

    expect($r->json('data'))->toBeNull();
})->group('alta-de-domiciliario');

it('dice si ya es de tu tienda, para no vincularlo dos veces', function () {
    $d = domiciliarioConTelefono('305 111 2233');

    DB::table('business_domiciliary')->insert([
        'busines_id' => $this->negocio,
        'domiciliary_id' => $d->domiciliary_id,
        'state' => 1,
    ]);

    $r = $this->getJson('/v1/negocio/domiciliarios/buscar?q=3051112233');

    expect($r->json('data.ya_es_tuyo'))->toBeTrue();
})->group('alta-de-domiciliario');

/* ---------------------------------------------------------------------- */

/**
 * QUITARLO DE LA TIENDA.
 *
 * Se podia dar de alta y editar, pero no quitar. La gente se va --deja de
 * repartir, se cambia de barrio-- o la tienda lo da de alta por error, y sin
 * esto seguia saliendo en la torre, en el elegidor de «despachar» y en el
 * efectivo para siempre.
 *
 * Lo que importa de verdad aca son las dos negativas: a quien lleva pedidos
 * encima o efectivo de la tienda NO se le quita. Desvincularlo lo borra de la
 * torre mientras sigue en la calle con la plata, y el tendero pierde la
 * pantalla donde verlo justo cuando mas la necesita.
 */
function conPedidoEncima(int $negocio, int $domiciliario, int $estado): void
{
    /* El `beforeEach` siembra tendero y domiciliario; el comprador hace falta
       solo aca, y sin su fila en `rol` el `user` no nace (clave ajena). */
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $comprador = User::factory()->create(['rol' => 1]);
    $buyerId = DB::table('buyer')->insertGetId([
        'user_id' => $comprador->user_id, 'qualification' => 0, 'state' => 1,
    ], 'buyer_id');

    DB::table('payment_methods')->insertOrIgnore([
        'methods_id' => 1, 'name' => 'Efectivo', 'state' => 1,
    ]);

    DB::table('orderssales')->insert([
        'buyer_id' => $buyerId, 'busines_id' => $negocio,
        'domiciliary_id' => $domiciliario,
        'subtotal' => 20000, 'domicilio' => 0, 'total' => 20000,
        'sale_date' => now(), 'state' => $estado,
        'payment_state' => 'paid', 'methods_id' => 1,
    ]);
}

it('lo quita de la tienda sin borrar su cuenta', function () {
    $d = domiciliarioConTelefono('305 400 1122');
    DB::table('business_domiciliary')->insert([
        'busines_id' => $this->negocio, 'domiciliary_id' => $d->domiciliary_id, 'state' => 1,
    ]);

    $this->deleteJson('/v1/negocio/domiciliarios/'.$d->domiciliary_id)
        ->assertOk();

    // El vinculo baja; la persona y su ficha siguen ahi.
    expect(DB::table('business_domiciliary')
        ->where('busines_id', $this->negocio)
        ->where('domiciliary_id', $d->domiciliary_id)
        ->value('state'))->toEqual(0)
        ->and(Domiciliary::find($d->domiciliary_id))->not->toBeNull();
})->group('alta-de-domiciliario');

it('y deja de salir en la torre', function () {
    $d = domiciliarioConTelefono('305 400 3344');
    DB::table('business_domiciliary')->insert([
        'busines_id' => $this->negocio, 'domiciliary_id' => $d->domiciliary_id, 'state' => 1,
    ]);

    expect($this->getJson('/v1/negocio/torre')->assertOk()->json('flota'))
        ->toHaveCount(1);

    $this->deleteJson('/v1/negocio/domiciliarios/'.$d->domiciliary_id)->assertOk();

    expect($this->getJson('/v1/negocio/torre')->assertOk()->json('flota'))
        ->toHaveCount(0);
})->group('alta-de-domiciliario');

it('NO se quita a quien lleva un pedido encima', function () {
    $d = domiciliarioConTelefono('305 400 5566');
    DB::table('business_domiciliary')->insert([
        'busines_id' => $this->negocio, 'domiciliary_id' => $d->domiciliary_id, 'state' => 1,
    ]);
    conPedidoEncima($this->negocio, $d->domiciliary_id, OrdersSales::EN_CAMINO);

    $r = $this->deleteJson('/v1/negocio/domiciliarios/'.$d->domiciliary_id)
        ->assertStatus(422);

    expect($r->json('message'))->toContain('un pedido tuyo encima')
        ->and(DB::table('business_domiciliary')
            ->where('domiciliary_id', $d->domiciliary_id)->value('state'))->toEqual(1);
})->group('alta-de-domiciliario');

it('un pedido ya entregado no lo retiene', function () {
    // Lo que entrego, entregado esta: eso no es motivo para dejarlo pegado.
    $d = domiciliarioConTelefono('305 400 7788');
    DB::table('business_domiciliary')->insert([
        'busines_id' => $this->negocio, 'domiciliary_id' => $d->domiciliary_id, 'state' => 1,
    ]);
    conPedidoEncima($this->negocio, $d->domiciliary_id, OrdersSales::ENTREGADO);

    $this->deleteJson('/v1/negocio/domiciliarios/'.$d->domiciliary_id)->assertOk();
})->group('alta-de-domiciliario');

it('el de otra tienda da 404, no 403', function () {
    // Confirmar que existe pero es de otro ya dice algo de la flota del vecino.
    $d = domiciliarioConTelefono('305 400 9900');

    $this->deleteJson('/v1/negocio/domiciliarios/'.$d->domiciliary_id)
        ->assertStatus(404);
})->group('alta-de-domiciliario');

it('se puede volver a vincular al que se quito', function () {
    // La fila sigue ahi con `state = 0`; `vincular()` sabe resucitarla.
    $d = domiciliarioConTelefono('305 401 1122');
    DB::table('business_domiciliary')->insert([
        'busines_id' => $this->negocio, 'domiciliary_id' => $d->domiciliary_id, 'state' => 1,
    ]);

    $this->deleteJson('/v1/negocio/domiciliarios/'.$d->domiciliary_id)->assertOk();

    $this->postJson('/v1/negocio/domiciliarios', [
        'domiciliary_id' => $d->domiciliary_id,
        'q' => '3054011122',
    ])->assertStatus(201);

    expect(DB::table('business_domiciliary')
        ->where('domiciliary_id', $d->domiciliary_id)->value('state'))->toEqual(1);
})->group('alta-de-domiciliario');
