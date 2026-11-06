<?php

use App\Models\Rol;
use App\Models\User;
use App\Services\CreditoDeTienda;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * EL TABLERO ES LO DE AHORA; EL ARCHIVO ES OTRA PANTALLA.
 *
 * El endpoint del tablero devolvia TODOS los pedidos confirmados del negocio
 * desde que abrio. Dos consecuencias: la respuesta crece sin tope, y la
 * columna «Entregados» acababa enseñando entregas de hace meses bajo un
 * rotulo que dice «hoy».
 *
 * Lo que se prueba aca es la linea que separa las dos cosas, que es donde
 * esto se puede romper en silencio:
 *
 *  1. los pedidos ABIERTOS no se acotan por fecha —uno sin aceptar de hace
 *     seis meses sigue sin aceptar, y esconderlo por viejo es esconder el
 *     problema—;
 *  2. los CERRADOS si, al dia;
 *  3. el historial los recupera, con su rango.
 */
function tiendaConHistorial(array $pedidos): array
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

    foreach ([1 => 'Efectivo', 2 => 'Tarjeta', CreditoDeTienda::METODO => 'Fiado'] as $id => $n) {
        DB::table('payment_methods')->insertOrIgnore([
            'methods_id' => $id, 'name' => $n, 'state' => 1,
        ]);
    }

    $comprador = User::factory()->create(['rol' => 1]);
    $buyerId = DB::table('buyer')->insertGetId([
        'user_id' => $comprador->user_id, 'qualification' => 0, 'state' => 1,
    ]);

    foreach ($pedidos as [$estado, $haceDias, $metodo, $total]) {
        DB::table('orderssales')->insert([
            'buyer_id' => $buyerId, 'busines_id' => $businessId,
            'subtotal' => $total, 'domicilio' => 0, 'total' => $total,
            'sale_date' => now()->subDays($haceDias),
            'state' => $estado, 'payment_state' => 'paid', 'methods_id' => $metodo,
        ]);
    }

    return compact('tendero', 'businessId');
}

/* ------------------------------------------------------------------ */
/* EL TABLERO                                                          */
/* ------------------------------------------------------------------ */

test('un pedido sin aceptar de hace meses sigue en el tablero', function () {
    // Es la regla que importa: lo abierto NO se acota por fecha. Si se
    // acotara, el tendero dejaria de ver un pedido que nadie atendio —y el
    // vecino seguiria esperando sin que nadie lo supiera.
    $t = tiendaConHistorial([[1, 180, 1, 20000], [2, 90, 1, 15000], [3, 45, 2, 30000]]);

    Sanctum::actingAs($t['tendero']);
    $r = $this->getJson("/v1/negocio/pedidos?business_id={$t['businessId']}")->assertOk();

    expect($r->json('orders'))->toHaveCount(3);
});

test('los entregados de hace meses salen del tablero', function () {
    $t = tiendaConHistorial([
        [4, 60, 1, 20000],   // entregado hace dos meses: al archivo
        [5, 30, 1, 10000],   // cancelado hace un mes: al archivo
        [4, 0, 1, 18000],    // entregado hoy: se queda
        [1, 200, 1, 12000],  // sin aceptar: se queda, venga de cuando venga
    ]);

    Sanctum::actingAs($t['tendero']);
    $r = $this->getJson("/v1/negocio/pedidos?business_id={$t['businessId']}")->assertOk();

    $estados = collect($r->json('orders'))->pluck('state')->sort()->values()->all();

    expect($estados)->toBe([1, 4]);
});

/* ------------------------------------------------------------------ */
/* EL ARCHIVO                                                          */
/* ------------------------------------------------------------------ */

test('sin fechas, el historial es la semana en curso', function () {
    $t = tiendaConHistorial([
        [4, 0, 1, 10000],
        [4, 40, 1, 90000],   // de hace mas de un mes: fuera de la semana
    ]);

    Sanctum::actingAs($t['tendero']);
    $r = $this->getJson('/v1/negocio/pedidos/historial')->assertOk();

    expect($r->json('periodo.desde'))->toBe(now()->startOfWeek()->toDateString())
        ->and($r->json('totales.pedidos'))->toBe(1)
        ->and($r->json('totales.vendido'))->toEqual(10000);
});

test('solo trae pedidos cerrados: lo abierto vive en el tablero', function () {
    $t = tiendaConHistorial([[1, 0, 1, 10000], [2, 0, 1, 20000], [4, 0, 1, 30000]]);

    Sanctum::actingAs($t['tendero']);
    $r = $this->getJson('/v1/negocio/pedidos/historial')->assertOk();

    expect($r->json('totales.pedidos'))->toBe(1)
        ->and($r->json('totales.entregados'))->toBe(1);
});

test('el filtro de pago agrupa en tres bolsillos, no en siete metodos', function () {
    $t = tiendaConHistorial([
        [4, 0, 1, 10000],                        // efectivo
        [4, 0, 2, 20000],                        // tarjeta -> «en la app»
        [4, 0, CreditoDeTienda::METODO, 30000],  // fiado
    ]);

    Sanctum::actingAs($t['tendero']);

    /* Se mira la TABLA, no las cifras: los filtros acotan la lista y las
       cifras de arriba siguen resumiendo el periodo entero. Si cambiaran con
       cada filtro, «Cancelados 2» al lado de «Pedidos 46» no se podria leer
       —serian dos respuestas a preguntas distintas con la misma pinta—. */
    expect($this->getJson('/v1/negocio/pedidos/historial?pago=efectivo')->json('pedidos.total'))->toBe(1)
        ->and($this->getJson('/v1/negocio/pedidos/historial?pago=app')->json('pedidos.total'))->toBe(1)
        ->and($this->getJson('/v1/negocio/pedidos/historial?pago=fiado')->json('pedidos.total'))->toBe(1)
        // Y las cifras no se mueven: son del periodo.
        ->and($this->getJson('/v1/negocio/pedidos/historial?pago=fiado')->json('totales.pedidos'))->toBe(3);
});

test('las cifras son de TODO el rango, no de la pagina', function () {
    // Treinta entregados: la primera pagina trae 25, pero el resumen tiene
    // que contarlos todos. Un total que cambiara al pasar de pagina no
    // resume nada.
    $t = tiendaConHistorial(array_fill(0, 30, [4, 0, 1, 1000]));

    Sanctum::actingAs($t['tendero']);
    $r = $this->getJson('/v1/negocio/pedidos/historial')->assertOk();

    expect($r->json('totales.pedidos'))->toBe(30)
        ->and($r->json('totales.vendido'))->toEqual(30000)
        ->and($r->json('pedidos.data'))->toHaveCount(25);
});

test('un rango de anios se recorta a tres meses', function () {
    // Sin tope, una fecha de hace cinco anios hace recorrer la tabla entera
    // para pintar una pantalla que nadie lee.
    $t = tiendaConHistorial([[4, 0, 1, 1000]]);

    Sanctum::actingAs($t['tendero']);
    $desde = now()->subYears(5)->toDateString();
    $r = $this->getJson("/v1/negocio/pedidos/historial?desde={$desde}")->assertOk();

    expect(abs(now()->diffInDays($r->json('periodo.desde'))))->toBeLessThanOrEqual(93);
});

test('las fechas al reves se corrigen en vez de no devolver nada', function () {
    // Es un error de dedo, no una consulta sin resultados.
    $t = tiendaConHistorial([[4, 3, 1, 5000]]);

    Sanctum::actingAs($t['tendero']);
    $hoy = now()->toDateString();
    $haceUnaSemana = now()->subDays(7)->toDateString();

    $r = $this->getJson("/v1/negocio/pedidos/historial?desde={$hoy}&hasta={$haceUnaSemana}")
        ->assertOk();

    expect($r->json('periodo.desde'))->toBe($haceUnaSemana)
        ->and($r->json('totales.pedidos'))->toBe(1);
});

/* ------------------------------------------------------------------ */
/* «ENTREGADOS A TIEMPO»                                               */
/* ------------------------------------------------------------------ */

/**
 * «ENTREGADOS A TIEMPO», Y SOBRE CUANTOS SE PUEDE DECIR.
 *
 * La cifra solo tiene sentido sobre los pedidos que traen las dos marcas
 * —cuando salio y cuando se entrego— y un plazo prometido congelado. Los
 * viejos no las tienen.
 *
 * Lo que se prueba es que NO se invente: con cero medibles no sale «0 %»
 * —que se leeria como «ninguno llego a tiempo»— sino nada, y el porcentaje
 * se calcula sobre los medibles y no sobre el total de entregados, que es el
 * error que convierte «3 de 3» en «100 % de cuarenta».
 */
function tiendaConEntregas(array $entregas): array
{
    $t = tiendaConHistorial([]);
    $buyerId = DB::table('buyer')->value('buyer_id');

    foreach ($entregas as [$prometidos, $tardo]) {
        $salida = now()->subHours(3);

        DB::table('orderssales')->insert([
            'buyer_id' => $buyerId, 'busines_id' => $t['businessId'],
            'subtotal' => 10000, 'domicilio' => 0, 'total' => 10000,
            'sale_date' => now()->subHours(4),
            'state' => 4, 'payment_state' => 'paid', 'methods_id' => 1,
            'dispatched_at'     => $prometidos === null ? null : $salida,
            'delivery_date'     => $tardo === null ? null : $salida->copy()->addMinutes($tardo),
            'promised_minutes'  => $prometidos,
        ]);
    }

    return $t;
}

test('el porcentaje se calcula sobre los medibles, no sobre todos', function () {
    $t = tiendaConEntregas([
        [30, 20],        // a tiempo
        [30, 45],        // tarde
        [null, null],    // sin marcas: no se puede medir
        [null, null],
    ]);

    Sanctum::actingAs($t['tendero']);
    $r = $this->getJson('/v1/negocio/pedidos/historial')->assertOk();

    expect($r->json('totales.entregados'))->toBe(4)
        ->and($r->json('totales.a_tiempo.medidos'))->toBe(2)
        ->and($r->json('totales.a_tiempo.a_tiempo'))->toBe(1)
        ->and($r->json('totales.a_tiempo.porcentaje'))->toBe(50);
});

test('sin pedidos medibles no se inventa un cero', function () {
    // «0 %» se leeria como «ninguno llego a tiempo», que es una acusacion.
    // Lo que pasa es que no hay con que medirlo.
    $t = tiendaConEntregas([[null, null], [null, null]]);

    Sanctum::actingAs($t['tendero']);
    $r = $this->getJson('/v1/negocio/pedidos/historial')->assertOk();

    expect($r->json('totales.a_tiempo.medidos'))->toBe(0)
        ->and($r->json('totales.a_tiempo.porcentaje'))->toBeNull();
});

test('justo en el plazo cuenta como a tiempo', function () {
    // El limite es «no mas de lo prometido», no «menos de». Treinta minutos
    // prometidos y treinta tardados es un plazo cumplido.
    $t = tiendaConEntregas([[30, 30]]);

    Sanctum::actingAs($t['tendero']);
    $r = $this->getJson('/v1/negocio/pedidos/historial')->assertOk();

    expect($r->json('totales.a_tiempo.porcentaje'))->toBe(100);
});
