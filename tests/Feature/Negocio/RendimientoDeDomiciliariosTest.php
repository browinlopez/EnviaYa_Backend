<?php

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * CÓMO LE VA A CADA DOMICILIARIO, PARA LA TIENDA.
 *
 * El tendero solo podía ver una foto del ahora —cuántos pedidos lleva encima
 * cada uno y cuánto efectivo— y ni una cifra de histórico. Para decidir a
 * quién llamar primero mañana hace falta saber quién entrega, cuánto tarda y
 * quién cumple el plazo.
 *
 * La consulta ya existía para el panel de administración. Lo que se prueba acá
 * es lo que cambia al abrirla a otro rol: que cada tienda vea LA SUYA, y que
 * no pueda ver la de al lado ni pidiéndolo a propósito.
 */

/** Un domiciliario de esta tienda. */
function domiciliarioDe(int $businessId, string $nombre): int
{
    \App\Models\Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);

    $user = \App\Models\User::factory()->create(['rol' => 3, 'name' => $nombre]);

    $domiId = DB::table('domiciliary')->insertGetId([
        'user_id' => $user->user_id, 'available' => 1,
        'qualification' => 0, 'state' => 1,
    ], 'domiciliary_id');

    DB::table('business_domiciliary')->insert([
        'busines_id' => $businessId, 'domiciliary_id' => $domiId, 'state' => 1,
    ]);

    return $domiId;
}

/** Una entrega ya hecha, con sus dos marcas de tiempo y su plazo prometido. */
function entregaHecha(int $businessId, int $domiId, int $minutosReales, ?int $plazo = 30): void
{
    DB::table('payment_methods')->insertOrIgnore([
        'methods_id' => 1, 'name' => 'Efectivo', 'state' => 1,
    ]);

    $despacho = now()->subHours(2);

    /*
     * Un comprador cualquiera, el mismo para todas: quien hizo el pedido no
     * importa aca --lo que se mide es quien lo llevo-- pero `orderssales`
     * tiene clave foranea y sin la fila el insert no entra.
     */
    $buyerId = DB::table('buyer')->value('buyer_id');

    if (!$buyerId) {
        \App\Models\Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

        $buyerId = DB::table('buyer')->insertGetId([
            'user_id'            => \App\Models\User::factory()->create(['rol' => 1])->user_id,
            'qualification'      => 0,
            'belongs_to_complex' => 0,
            'state'              => 1,
        ]);
    }

    DB::table('orderssales')->insert([
        'buyer_id'         => $buyerId,
        'busines_id'       => $businessId,
        'domiciliary_id'   => $domiId,
        'methods_id'       => 1,
        'subtotal'         => 10000,
        'domicilio'        => 2000,
        'total'            => 12000,
        'domiciliary_fee'  => 500,
        'sale_date'        => $despacho->copy()->subMinutes(10),
        'dispatched_at'    => $despacho,
        'delivery_date'    => $despacho->copy()->addMinutes($minutosReales),
        'promised_minutes' => $plazo,
        'state'            => 4,
        'payment_state'    => 'paid',
    ]);
}

function rendimiento(array $t, array $query = []): \Illuminate\Testing\TestResponse
{
    Sanctum::actingAs($t['user']);

    return test()->getJson('/v1/negocio/domiciliarios/rendimiento?' . http_build_query($query));
}

/* ---------------------------------------------------------------------- */

test('la tienda ve las entregas, el tiempo medio y el cumplimiento de cada uno', function () {
    $t = tenderoConCatalogo();
    $juan = domiciliarioDe($t['mio'], 'Juan Reparte');

    entregaHecha($t['mio'], $juan, 20);   // a tiempo
    entregaHecha($t['mio'], $juan, 40);   // tarde

    $r = rendimiento($t)->assertOk();

    $suyos = collect($r->json('domiciliarios'));

    expect($suyos)->toHaveCount(1);

    $juanito = $suyos->first();

    expect($juanito['name'])->toBe('Juan Reparte')
        ->and((int) $juanito['delivered'])->toBe(2)
        ->and((float) $juanito['avg_minutes'])->toBe(30.0)
        ->and((int) $juanito['measured'])->toBe(2)
        ->and((int) $juanito['on_time'])->toBe(1)
        ->and((int) $juanito['late'])->toBe(1)
        ->and((float) $juanito['on_time_rate'])->toBe(50.0);
});

test('no se mezclan los domiciliarios ni las entregas de otra tienda', function () {
    $t = tenderoConCatalogo();
    $mio = domiciliarioDe($t['mio'], 'El Mío');
    $ajeno = domiciliarioDe($t['vecino'], 'El de Enfrente');

    entregaHecha($t['mio'], $mio, 25);
    entregaHecha($t['vecino'], $ajeno, 25);

    $r = rendimiento($t)->assertOk();

    expect(collect($r->json('domiciliarios'))->pluck('name')->all())->toBe(['El Mío']);
});

test('pedir el rendimiento de otra tienda no sirve de nada', function () {
    /*
     * El filtro `business_id` existe en el reporte original porque allá lo usa
     * un administrador. Acá lo pone la sesión y se pisa lo que mande el
     * cliente: si no, un tendero escribiría el número del vecino y vería cómo
     * le va a su competencia.
     */
    $t = tenderoConCatalogo();
    $mio = domiciliarioDe($t['mio'], 'El Mío');
    $ajeno = domiciliarioDe($t['vecino'], 'El de Enfrente');

    entregaHecha($t['mio'], $mio, 25);
    entregaHecha($t['vecino'], $ajeno, 25);

    $r = rendimiento($t, ['business_id' => $t['vecino']])->assertOk();

    expect(collect($r->json('domiciliarios'))->pluck('name')->all())->toBe(['El Mío']);
});

test('sin nada que medir dice que no se puede medir, y no cero por ciento', function () {
    /*
     * «0 % a tiempo» y «todavía no se le puede medir» son cosas muy distintas
     * para quien lo lee, y la diferencia es si el domiciliario queda mal
     * retratado por pedidos anteriores al compromiso de plazo.
     */
    $t = tenderoConCatalogo();
    $juan = domiciliarioDe($t['mio'], 'Juan Reparte');

    entregaHecha($t['mio'], $juan, 20, null);   // entregado, pero sin plazo prometido

    $juanito = collect(rendimiento($t)->assertOk()->json('domiciliarios'))->first();

    expect((int) $juanito['delivered'])->toBe(1)
        ->and((int) $juanito['measured'])->toBe(0)
        ->and($juanito['on_time_rate'])->toBeNull();
});

test('un comprador no puede mirar el rendimiento de los domiciliarios', function () {
    \App\Models\Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    Sanctum::actingAs(\App\Models\User::factory()->create(['rol' => 1]));

    test()->getJson('/v1/negocio/domiciliarios/rendimiento')->assertForbidden();
});
