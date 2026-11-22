<?php

use App\Models\Operacion\SafetyIncident;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * EL BOTÓN DE SOS.
 *
 * Lo que se fija acá no es que el endpoint responda 201: es que responda 201
 * EN LAS CONDICIONES MALAS, que son las únicas en las que se va a usar. Sin
 * coordenadas porque el GPS no fijó, sin motivo porque no dio tiempo, y
 * apretado tres veces seguidas porque la persona está asustada.
 *
 * El resto —de quién es la alerta y quién puede cambiarla— es lo de siempre:
 * sin eso bastaría con probar números para tocar la emergencia de otro.
 */
beforeEach(function () {
    foreach ([1 => 'comprador', 2 => 'tendero', 3 => 'domiciliario'] as $id => $n) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $n, 'guard_name' => 'web']);
    }
});

function domiciliarioConSos(): User
{
    $u = User::factory()->create(['rol' => 3, 'phone' => '3002464966']);

    DB::table('domiciliary')->insert([
        'user_id' => $u->user_id,
        'available' => 1,
        'state' => 1,
        'qualification' => 0,
    ]);

    return $u;
}

function tenderoConSos(): array
{
    $u = User::factory()->create(['rol' => 2, 'phone' => '3001112233']);

    $ownerId = DB::table('owner')->insertGetId(
        ['user_id' => $u->user_id, 'state' => 1], 'owner_id',
    );
    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId([
        'name' => 'Tienda de la esquina', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');
    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $negocio, 'state' => 1,
    ]);

    return [$u, $negocio];
}

/* =========================================================================
   LO QUE TIENE QUE PASAR AUNQUE NO LLEGUE NADA
   ========================================================================= */

test('un SOS sin coordenadas y sin motivo entra igual', function () {
    $u = domiciliarioConSos();

    $r = $this->actingAs($u)->postJson('/v1/sos', []);

    $r->assertCreated()->assertJsonStructure(['message', 'id']);

    $i = SafetyIncident::find($r->json('id'));

    expect($i->type)->toBe('otro')
        ->and($i->severity)->toBe('grave')
        ->and($i->state)->toBe(SafetyIncident::ABIERTO)
        ->and($i->latitude)->toBeNull()
        ->and($i->description)->toContain('3002464966');
});

test('el SOS del domiciliario queda colgado de su ficha', function () {
    $u = domiciliarioConSos();
    $suyo = DB::table('domiciliary')->where('user_id', $u->user_id)->value('domiciliary_id');

    $r = $this->actingAs($u)->postJson('/v1/sos', [
        'latitude' => 10.9685,
        'longitude' => -74.7813,
    ]);

    $i = SafetyIncident::find($r->json('id'));

    expect((int) $i->domiciliary_id)->toBe((int) $suyo)
        ->and($i->business_id)->toBeNull()
        // Y en texto, que es lo único que sabe leer la página del panel.
        ->and($i->location)->toBe('10.968500, -74.781300');
});

test('el SOS del tendero dice de qué tienda salió', function () {
    [$u, $negocio] = tenderoConSos();

    $r = $this->actingAs($u)->postJson('/v1/sos', ['type' => 'extorsion']);

    $i = SafetyIncident::find($r->json('id'));

    expect((int) $i->business_id)->toBe((int) $negocio)
        ->and($i->domiciliary_id)->toBeNull()
        ->and($i->type)->toBe('extorsion');
});

/* =========================================================================
   APRETAR DOS VECES NO SON DOS EMERGENCIAS
   ========================================================================= */

test('tres toques seguidos son una sola alerta, con la última posición', function () {
    $u = domiciliarioConSos();

    $primero = $this->actingAs($u)->postJson('/v1/sos', [
        'latitude' => 10.9685, 'longitude' => -74.7813,
    ])->assertCreated()->json('id');

    $segundo = $this->actingAs($u)->postJson('/v1/sos', [
        'latitude' => 10.9700, 'longitude' => -74.7800,
    ])->assertOk()->json('id');

    $this->actingAs($u)->postJson('/v1/sos', [])->assertOk();

    expect($segundo)->toBe($primero)
        ->and(SafetyIncident::count())->toBe(1);

    // Se movió, y eso sí se guarda: es lo único que cambió.
    expect(SafetyIncident::find($primero)->location)->toBe('10.970000, -74.780000');
});

test('pasada la ventana, un SOS nuevo es un SOS nuevo', function () {
    $u = domiciliarioConSos();

    $viejo = $this->actingAs($u)->postJson('/v1/sos', [])->json('id');

    SafetyIncident::find($viejo)->forceFill(['created_at' => now()->subMinutes(10)])->save();

    $nuevo = $this->actingAs($u)->postJson('/v1/sos', [])->assertCreated()->json('id');

    expect($nuevo)->not->toBe($viejo)
        ->and(SafetyIncident::count())->toBe(2);
});

/* =========================================================================
   AFINAR EL MOTIVO
   ========================================================================= */

test('la hoja de después cambia el motivo de la propia alerta', function () {
    $u = domiciliarioConSos();
    $id = $this->actingAs($u)->postJson('/v1/sos', [])->json('id');

    $this->actingAs($u)->patchJson("/v1/sos/{$id}", ['type' => 'robo'])->assertOk();

    $i = SafetyIncident::find($id);

    expect($i->type)->toBe('robo')
        ->and($i->description)->toContain('Motivo indicado: robo');
});

test('no se puede afinar la alerta de otra persona', function () {
    $mia = domiciliarioConSos();
    $suya = domiciliarioConSos();

    $id = $this->actingAs($suya)->postJson('/v1/sos', [])->json('id');

    $this->actingAs($mia)->patchJson("/v1/sos/{$id}", ['type' => 'robo'])->assertForbidden();

    expect(SafetyIncident::find($id)->type)->toBe('otro');
});

test('una alerta que el equipo ya tocó no la cambia el teléfono', function () {
    $u = domiciliarioConSos();
    $id = $this->actingAs($u)->postJson('/v1/sos', [])->json('id');

    SafetyIncident::find($id)->forceFill(['state' => SafetyIncident::EN_INVESTIGACION])->save();

    $this->actingAs($u)->patchJson("/v1/sos/{$id}", ['type' => 'robo'])->assertStatus(409);
});

test('el motivo se limita a los que ofrece la hoja', function () {
    $u = domiciliarioConSos();
    $id = $this->actingAs($u)->postJson('/v1/sos', [])->json('id');

    // Existe en el enum de la tabla, pero no es algo que el teléfono ofrezca.
    $this->actingAs($u)->patchJson("/v1/sos/{$id}", ['type' => 'condicion_insegura'])
        ->assertStatus(422);
});

/* =========================================================================
   QUIÉN PUEDE
   ========================================================================= */

test('el comprador no tiene SOS', function () {
    $u = User::factory()->create(['rol' => 1]);

    $this->actingAs($u)->postJson('/v1/sos', [])->assertForbidden();

    expect(SafetyIncident::count())->toBe(0);
});

test('sin sesión no se manda nada', function () {
    $this->postJson('/v1/sos', [])->assertUnauthorized();
});

test('unas coordenadas imposibles no entran', function () {
    $u = domiciliarioConSos();

    $this->actingAs($u)->postJson('/v1/sos', ['latitude' => 999, 'longitude' => 0])
        ->assertStatus(422);
});
