<?php

use App\Models\Rol;
use App\Models\User;
use App\Services\CreditoDeTienda;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * DE QUÉ BOLSILLO SALIÓ LA VENTA DE LA SEMANA.
 *
 * La pantalla de Ingresos enseñaba el total y no de dónde venía. Al tendero
 * le cambia la semana que el 60 % entre en efectivo —ya está en el cajón— o
 * por la app —se lo liquidan después—, y el fiado decide si puede seguir
 * fiando.
 *
 * Son TRES bolsillos y no los siete métodos que existen en la base: efectivo
 * (1), crédito de la tienda (7) y todo lo demás —tarjetas, QR,
 * transferencias— que para el tendero es lo mismo. Lo que se prueba acá es
 * justo ese agrupamiento, que es donde esto se equivoca en silencio.
 */
function tenderoConVentas(array $ventas): array
{
    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $tendero = User::factory()->create(['rol' => 2]);
    $ownerId = DB::table('owner')->insertGetId(
        ['user_id' => $tendero->user_id, 'state' => 1], 'owner_id',
    );
    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $businessId = DB::table('business')->insertGetId([
        'name' => 'Mercadito del Buho', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');
    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $businessId, 'state' => 1,
    ]);

    // `orderssales.methods_id` es clave foránea: sin las filas de
    // `payment_methods` la inserción falla por integridad.
    foreach ([
        1 => 'Efectivo',
        2 => 'Tarjeta de crédito',
        4 => 'Transferencia bancaria',
        5 => 'Pago por QR',
        CreditoDeTienda::METODO => 'Crédito de la tienda',
    ] as $id => $nombre) {
        DB::table('payment_methods')->insertOrIgnore([
            'methods_id' => $id, 'name' => $nombre, 'state' => 1,
        ]);
    }

    $comprador = User::factory()->create(['rol' => 1]);
    $buyerId = DB::table('buyer')->insertGetId([
        'user_id' => $comprador->user_id, 'qualification' => 0, 'state' => 1,
    ]);

    foreach ($ventas as [$metodo, $total]) {
        DB::table('orderssales')->insert([
            'buyer_id' => $buyerId, 'busines_id' => $businessId,
            'subtotal' => $total, 'domicilio' => 0, 'total' => $total,
            // Dentro de la semana en curso, que es la ventana que mira.
            'sale_date' => now()->startOfWeek()->addHours(10),
            'state' => 4, 'payment_state' => 'paid',
            'methods_id' => $metodo,
        ]);
    }

    return compact('tendero', 'businessId');
}

test('las tarjetas, el QR y la transferencia son un solo bolsillo', function () {
    // 2 = tarjeta de crédito, 4 = transferencia, 5 = QR. Para el tendero es
    // la misma plata: la que le liquida la plataforma.
    $t = tenderoConVentas([[2, 10000], [4, 20000], [5, 30000]]);

    Sanctum::actingAs($t['tendero']);
    $r = $this->getJson('/v1/negocio/ingresos')->assertOk();

    $medios = collect($r->json('por_medio'));

    expect($medios)->toHaveCount(1)
        ->and($medios->first()['medio'])->toBe('Pagado en la app')
        ->and($medios->first()['total'])->toEqual(60000);
});

test('el efectivo y el fiado van cada uno por su lado', function () {
    $t = tenderoConVentas([
        [1, 50000],
        [CreditoDeTienda::METODO, 15000],
        [2, 25000],
    ]);

    Sanctum::actingAs($t['tendero']);
    $r = $this->getJson('/v1/negocio/ingresos')->assertOk();

    $medios = collect($r->json('por_medio'))->keyBy('medio');

    expect($medios)->toHaveCount(3)
        ->and($medios['Efectivo']['total'])->toEqual(50000)
        ->and($medios['Fiado']['total'])->toEqual(15000)
        ->and($medios['Pagado en la app']['total'])->toEqual(25000);
});

test('vienen de mayor a menor: el bolsillo gordo primero', function () {
    $t = tenderoConVentas([[CreditoDeTienda::METODO, 5000], [1, 90000], [2, 40000]]);

    Sanctum::actingAs($t['tendero']);
    $r = $this->getJson('/v1/negocio/ingresos')->assertOk();

    expect(collect($r->json('por_medio'))->pluck('medio')->all())
        ->toBe(['Efectivo', 'Pagado en la app', 'Fiado']);
});

test('sin ventas esta semana la lista viene vacía, no en cero', function () {
    // Una venta de la semana pasada no cuenta: la tarjeta dice «esta semana».
    $t = tenderoConVentas([]);
    DB::table('orderssales')->insert([
        'buyer_id' => DB::table('buyer')->value('buyer_id'),
        'busines_id' => $t['businessId'],
        'subtotal' => 80000, 'domicilio' => 0, 'total' => 80000,
        'sale_date' => now()->startOfWeek()->subDays(3),
        'state' => 4, 'payment_state' => 'paid', 'methods_id' => 1,
    ]);

    Sanctum::actingAs($t['tendero']);
    $r = $this->getJson('/v1/negocio/ingresos')->assertOk();

    expect($r->json('por_medio'))->toBe([]);
});

/* ---------------------------------------------------------------------- */

/**
 * EL RANGO QUE ELIGE EL TENDERO CON EL CALENDARIO.
 *
 * Dia, semana y mes los decide el servidor. Esto responde a una pregunta
 * concreta --«¿cuanto hice del 3 al 9?»-- y lo que hay que sostener es que
 * NO sea otra forma de contar: un rango que caiga justo en la semana tiene
 * que dar lo mismo que «Semana», o habria dos cifras y nadie sabria cual
 * creer.
 */
test('sin fechas, la respuesta es exactamente la de siempre', function () {
    $t = tenderoConVentas([[1, 50000]]);

    Sanctum::actingAs($t['tendero']);

    expect($this->getJson('/v1/negocio/ingresos')->assertOk()->json('rango'))
        ->toBeNull();
});

test('un rango que cubre la semana da LO MISMO que la semana', function () {
    // Si discreparan, serian dos formas distintas de contar la misma plata.
    $t = tenderoConVentas([[1, 50000], [2, 25000], [CreditoDeTienda::METODO, 15000]]);

    Sanctum::actingAs($t['tendero']);

    $normal = $this->getJson('/v1/negocio/ingresos')->assertOk();

    $desde = now()->startOfWeek()->toDateString();
    $hasta = now()->endOfWeek()->toDateString();
    $conRango = $this->getJson("/v1/negocio/ingresos?desde={$desde}&hasta={$hasta}")
        ->assertOk();

    expect((float) $conRango->json('rango.total'))
        ->toEqual((float) $normal->json('current_week_income'))
        ->and($conRango->json('rango.ventas'))->toBe(3)
        // Y el desglose se agrupa igual: tres bolsillos, no siete metodos.
        ->and(collect($conRango->json('rango.por_medio')))->toHaveCount(3);
});

test('un rango sin ventas da cero y no revienta', function () {
    $t = tenderoConVentas([[1, 50000]]);

    Sanctum::actingAs($t['tendero']);

    $hace = now()->subMonths(2)->toDateString();
    $r = $this->getJson("/v1/negocio/ingresos?desde={$hace}&hasta={$hace}")->assertOk();

    expect((float) $r->json('rango.total'))->toEqual(0.0)
        ->and($r->json('rango.ventas'))->toBe(0)
        ->and($r->json('rango.ticket_promedio'))->toEqual(0.0)
        ->and($r->json('rango.por_medio'))->toBe([]);
});

test('las fechas al reves se corrigen, no devuelven vacio', function () {
    // Es un error de dedo, no una consulta sin resultados.
    $t = tenderoConVentas([[1, 50000]]);

    Sanctum::actingAs($t['tendero']);

    $desde = now()->startOfWeek()->toDateString();
    $hasta = now()->endOfWeek()->toDateString();
    $r = $this->getJson("/v1/negocio/ingresos?desde={$hasta}&hasta={$desde}")
        ->assertOk();

    expect((float) $r->json('rango.total'))->toEqual(50000.0)
        ->and($r->json('rango.desde'))->toBe($desde);
});

test('media pareja de fechas no define un rango', function () {
    // Rellenar la que falta con una fecha inventada daria una cifra que
    // parece un dato.
    $t = tenderoConVentas([[1, 50000]]);

    Sanctum::actingAs($t['tendero']);

    $this->getJson('/v1/negocio/ingresos?desde='.now()->toDateString())
        ->assertStatus(422);
});

test('el dia a dia solo viaja cuando cabe en una pantalla', function () {
    // Con treinta y un dias salen treinta y una barras de ocho pixeles: una
    // mancha. Pasado el mes se manda vacio y la pantalla ensena las cifras.
    $t = tenderoConVentas([[1, 50000]]);

    Sanctum::actingAs($t['tendero']);

    $corto = $this->getJson('/v1/negocio/ingresos?desde='.now()->subDays(6)->toDateString()
        .'&hasta='.now()->toDateString())->assertOk();
    $largo = $this->getJson('/v1/negocio/ingresos?desde='.now()->subDays(60)->toDateString()
        .'&hasta='.now()->toDateString())->assertOk();

    expect($corto->json('rango.dia_a_dia'))->toHaveCount(7)
        ->and($largo->json('rango.dia_a_dia'))->toBe([]);
});
