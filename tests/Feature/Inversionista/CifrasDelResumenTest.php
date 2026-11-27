<?php

/**
 * LAS CIFRAS DEL RESUMEN Y DEL AVANCE POR CIUDAD DEL INVERSIONISTA.
 *
 * Con un escenario sembrado a mano donde cada número se puede contar con el
 * dedo: tres ciudades más «Sin ciudad», tres oficios, pedidos entregados y
 * pagados, y los que NO deben contar (cancelados, en curso, con el pago en
 * línea pendiente o rechazado, de un negocio inactivo).
 *
 * El 20 de noviembre de 2026 es «hoy»; el periodo pedido es del 1 al 20 de
 * noviembre, y el anterior, del 12 al 31 de octubre.
 */

use App\Models\Rol;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function invEscenario(): array
{
    Carbon::setTestNow('2026-11-20 12:00:00');

    foreach ([1 => 'comprador', 2 => 'tendero', 7 => 'inversionista'] as $id => $nombre) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $nombre, 'guard_name' => 'web']);
    }

    DB::table('payment_methods')->insertOrIgnore(['methods_id' => 1, 'name' => 'Efectivo', 'state' => 1]);

    $pais = DB::table('countries')->insertGetId(['name' => 'Colombia', 'iso_code' => 'CO']);
    $dep = DB::table('departments')->insertGetId(['name' => 'Atlántico', 'country_id' => $pais]);
    $valle = DB::table('departments')->insertGetId(['name' => 'Valle', 'country_id' => $pais]);
    $baq = DB::table('municipalities')->insertGetId(['name' => 'Barranquilla', 'department_id' => $dep]);
    $sol = DB::table('municipalities')->insertGetId(['name' => 'Soledad', 'department_id' => $dep]);
    $cali = DB::table('municipalities')->insertGetId(['name' => 'Cali', 'department_id' => $valle]);

    $tienda = DB::table('category_business')->insertGetId(['name' => 'Tienda']);
    $farmacia = DB::table('category_business')->insertGetId(['name' => 'Farmacia']);
    $restaurante = DB::table('category_business')->insertGetId(['name' => 'Restaurante']);

    $negocio = fn (string $nombre, int $tipo, ?int $ciudad, ?string $alta, int $estado = 1) => DB::table('business')->insertGetId([
        'name' => $nombre, 'qualification' => 0, 'state' => $estado, 'type' => $tipo,
        'municipality_id' => $ciudad, 'created_at' => $alta,
    ], 'busines_id');

    $n1 = $negocio('La Esquina de Doña Rosa', $tienda, $baq, null);
    $n2 = $negocio('Droguería El Alivio', $farmacia, $baq, '2026-11-05 09:00:00');
    $n3 = $negocio('Asadero Don Pepe', $restaurante, $sol, '2026-10-15 09:00:00');
    $n4 = $negocio('Tienda Sin Mapa', $tienda, null, null);
    $n5 = $negocio('Tienda Cerrada', $tienda, $baq, null, 0);

    DB::table('residential_complexes')->insert([
        ['name' => 'Conjunto Activo', 'state' => 1, 'municipality_id' => $baq],
        ['name' => 'Conjunto Inactivo', 'state' => 0, 'municipality_id' => $sol],
    ]);

    $comprador = function (string $nombre, string $correo, ?string $alta, ?int $ciudad) {
        $u = User::factory()->create(['name' => $nombre, 'email' => $correo, 'phone' => '3009998877', 'rol' => 1]);
        DB::table('user')->where('user_id', $u->user_id)->update(['created_at' => $alta]);
        $buyer = DB::table('buyer')->insertGetId(['user_id' => $u->user_id, 'qualification' => 0, 'state' => 1]);
        if ($ciudad) {
            DB::table('user_address')->insert([
                'user_id' => $u->user_id, 'address' => 'Calle 1 # 2-3', 'state' => 1, 'municipality_id' => $ciudad,
            ]);
        }

        return $buyer;
    };

    $b1 = $comprador('Marta Comprador', 'marta.secreta@correo.co', '2026-11-03 10:00:00', $baq);
    $comprador('Pedro Caleño', 'pedro.secreto@correo.co', '2026-10-20 10:00:00', $cali);
    $comprador('Sin Fecha', 'sin.fecha@correo.co', null, null);

    $pedido = fn (int $negocio, int $total, string $fecha, int $estado = 4, string $pago = 'paid') => DB::table('orderssales')->insertGetId([
        'buyer_id' => $b1, 'busines_id' => $negocio, 'methods_id' => 1,
        'subtotal' => $total, 'domicilio' => 0, 'total' => $total, 'domiciliary_fee' => 0,
        'sale_date' => $fecha, 'state' => $estado, 'payment_state' => $pago,
    ], 'orderSales_id');

    // Cuentan, en el periodo.
    $pedido($n1, 10000, '2026-11-02 12:00:00');
    $pedido($n1, 20000, '2026-11-15 12:00:00', pago: 'pending_cash');
    $pedido($n2, 30000, '2026-11-06 12:00:00');
    $pedido($n4, 5000, '2026-11-10 12:00:00');

    // No cuentan.
    $pedido($n2, 99999, '2026-11-07 12:00:00', estado: 5);
    $pedido($n1, 77777, '2026-11-08 12:00:00', pago: 'pending_online');
    $pedido($n1, 66666, '2026-11-09 12:00:00', pago: 'rejected');
    $pedido($n1, 55555, '2026-11-11 12:00:00', estado: 2);

    // El periodo anterior y la serie.
    $pedido($n3, 40000, '2026-10-20 12:00:00');
    $pedido($n1, 15000, '2026-09-15 12:00:00');

    $inversionista = User::factory()->create(['rol' => 7, 'name' => 'Quien Invierte']);
    Sanctum::actingAs($inversionista);

    return compact('baq', 'sol', 'cali', 'tienda', 'farmacia', 'restaurante', 'n5');
}

function invModulo(string $modulo, array $filtros = []): array
{
    $q = http_build_query(['desde' => '2026-11-01', 'hasta' => '2026-11-20'] + $filtros);

    return test()->getJson('/v1/inversionista/'.$modulo.'?'.$q)->assertOk()->json();
}

/** Lo que antes devolvía el tablero entero: el resumen y el avance por ciudad. */
function invResumenYCiudades(array $filtros = []): array
{
    return invModulo('resumen', $filtros) + [
        'por_ciudad' => invModulo('expansion', $filtros)['por_ciudad'],
        'opciones' => test()->getJson('/v1/inversionista/opciones')->assertOk()->json(),
    ];
}

/* Estas pruebas miran la plata (ventas, ticket): con el interruptor
   `inversionista.ver_ingresos` encendido. Apagado, ver
   ModulosDelInversionistaTest. */
beforeEach(fn () => config(['inversionista.ver_ingresos' => true]));

afterEach(fn () => Carbon::setTestNow());

test('las cifras de arriba solo cuentan lo entregado y pagado', function () {
    invEscenario();
    $t = invResumenYCiudades();

    expect($t['periodo'])->toMatchArray([
        'desde' => '2026-11-01', 'hasta' => '2026-11-20', 'dias' => 20,
        'desde_anterior' => '2026-10-12', 'hasta_anterior' => '2026-10-31',
    ]);

    $c = $t['cifras'];

    // 10.000 + 20.000 + 30.000 + 5.000: ni el cancelado, ni el en curso, ni
    // los de pago en línea pendiente o rechazado.
    expect($c['pedidos'])->toMatchArray(['valor' => 4, 'anterior' => 1])
        ->and((float) $c['pedidos']['variacion'])->toBe(300.0)
        ->and((float) $c['ventas']['valor'])->toBe(65000.0)
        ->and((float) $c['ventas']['anterior'])->toBe(40000.0)
        ->and((float) $c['ticket_promedio']['valor'])->toBe(16250.0)
        ->and((float) $c['ticket_promedio']['anterior'])->toBe(40000.0);

    // Activos: los cuatro visibles (el cerrado no). Al cierre de octubre aún
    // no existía la droguería. Con pedidos: la esquina, la droguería y la de
    // sin ciudad.
    expect($c['negocios_activos'])->toMatchArray(['valor' => 4, 'anterior' => 3, 'con_pedidos' => 3]);

    // Compradores: tres cuentas de rol 1 (no el inversionista). La que no
    // tiene fecha cuenta en el total, nunca en «nuevos».
    expect($c['usuarios_registrados'])->toMatchArray([
        'valor' => 3, 'anterior' => 2, 'nuevos' => 1, 'nuevos_anterior' => 1,
    ]);
});

test('el reparto por tipo usa el nombre del panel y no se filtra por tipo', function () {
    $e = invEscenario();

    foreach ([[], ['tipo' => $e['tienda']]] as $filtros) {
        $porTipo = collect(invResumenYCiudades($filtros)['por_tipo'])->keyBy('clave');

        expect($porTipo->keys()->all())->toBe(['tienda', 'drogueria', 'restaurante'])
            ->and($porTipo['drogueria']['nombre'])->toBe('Droguería')
            ->and($porTipo['tienda'])->toMatchArray(['negocios' => 2, 'pedidos' => 3])
            ->and((float) $porTipo['tienda']['porcentaje'])->toBe(50.0)
            ->and((float) $porTipo['tienda']['ventas'])->toBe(35000.0)
            ->and($porTipo['drogueria'])->toMatchArray(['negocios' => 1, 'pedidos' => 1])
            ->and($porTipo['restaurante'])->toMatchArray(['negocios' => 1, 'pedidos' => 0]);
    }
});

test('el filtro de tipo recorta las cifras de arriba', function () {
    $e = invEscenario();
    $c = invResumenYCiudades(['tipo' => $e['tienda']])['cifras'];

    expect($c['pedidos']['valor'])->toBe(3)
        ->and((float) $c['ventas']['valor'])->toBe(35000.0)
        ->and($c['negocios_activos']['valor'])->toBe(2);
});

test('el avance por ciudad lleva la fila Sin ciudad al final', function () {
    invEscenario();
    $ciudades = invResumenYCiudades()['por_ciudad'];

    expect(array_column($ciudades, 'ciudad'))->toBe(['Barranquilla', 'Soledad', 'Cali', 'Sin ciudad']);

    [$baq, $sol, $cali, $sin] = $ciudades;

    expect($baq)->toMatchArray([
        'departamento' => 'Atlántico', 'negocios' => 2, 'conjuntos' => 1,
        'pedidos' => 3, 'compradores' => 1, 'desde' => '2026-11-05',
    ])->and((float) $baq['ventas'])->toBe(60000.0)
        ->and(collect($baq['por_tipo'])->pluck('negocios', 'clave')->all())
        ->toEqual(['tienda' => 1, 'drogueria' => 1]);

    // El conjunto inactivo no cuenta; el pedido de octubre no es del periodo.
    expect($sol)->toMatchArray(['negocios' => 1, 'conjuntos' => 0, 'pedidos' => 0, 'desde' => '2026-10-15']);

    // Cali solo tiene un comprador: demanda sin oferta.
    expect($cali)->toMatchArray(['negocios' => 0, 'compradores' => 1, 'desde' => null]);

    expect($sin)->toMatchArray([
        'municipality_id' => null, 'negocios' => 1, 'pedidos' => 1, 'compradores' => 1,
    ])->and((float) $sin['ventas'])->toBe(5000.0);
});

test('con una ciudad elegida todo se acota a ella y no sale Sin ciudad', function () {
    $e = invEscenario();
    $t = invResumenYCiudades(['municipality_id' => $e['baq']]);

    expect(array_column($t['por_ciudad'], 'ciudad'))->toBe(['Barranquilla'])
        ->and($t['cifras']['pedidos']['valor'])->toBe(3)
        ->and((float) $t['cifras']['ventas']['valor'])->toBe(60000.0)
        ->and($t['cifras']['usuarios_registrados']['valor'])->toBe(1)
        ->and($t['cifras']['negocios_activos']['valor'])->toBe(2);

    // Las opciones del selector no se recortan con el filtro.
    expect(array_column($t['opciones']['ciudades'], 'nombre'))->toBe(['Barranquilla', 'Soledad']);
});

test('la serie mensual son doce meses con su variacion', function () {
    invEscenario();
    $meses = collect(invResumenYCiudades()['meses'])->keyBy('mes');

    expect($meses)->toHaveCount(12)
        ->and($meses->keys()->first())->toBe('2025-12')
        ->and($meses->keys()->last())->toBe('2026-11');

    expect($meses['2026-11'])->toMatchArray([
        'etiqueta' => 'nov', 'en_curso' => true, 'pedidos' => 4,
        'negocios_nuevos' => 1, 'usuarios_nuevos' => 1,
    ])->and((float) $meses['2026-11']['ventas'])->toBe(65000.0)
        ->and((float) $meses['2026-11']['variacion']['pedidos'])->toBe(300.0)
        ->and((float) $meses['2026-11']['variacion']['ventas'])->toBe(62.5);

    expect($meses['2026-10'])->toMatchArray(['pedidos' => 1, 'en_curso' => false, 'negocios_nuevos' => 1])
        ->and((float) $meses['2026-10']['variacion']['pedidos'])->toBe(0.0);

    // Contra un mes vacío no hay porcentaje que inventar.
    expect($meses['2026-09']['pedidos'])->toBe(1)
        ->and($meses['2026-09']['variacion']['pedidos'])->toBeNull();
});

test('la respuesta no trae ni un dato de una persona ni de un pedido', function () {
    invEscenario();
    $t = invResumenYCiudades();
    $crudo = json_encode($t, JSON_UNESCAPED_UNICODE);

    foreach ([
        'Marta Comprador', 'marta.secreta@correo.co', 'Pedro Caleño', 'pedro.secreto@correo.co',
        'Sin Fecha', '3009998877', 'Calle 1', 'Quien Invierte',
        'La Esquina de Doña Rosa', 'Droguería El Alivio', 'Asadero Don Pepe', 'Tienda Sin Mapa',
        'Conjunto Activo',
    ] as $dato) {
        expect($crudo)->not->toContain($dato);
    }

    $prohibidas = [
        'name', 'email', 'phone', 'address', 'document', 'user_id', 'buyer_id',
        'busines_id', 'business_id', 'orderSales_id', 'order_id', 'complex_id',
    ];

    $claves = [];
    $recorrer = function ($v) use (&$recorrer, &$claves) {
        if (! is_array($v)) {
            return;
        }
        foreach ($v as $k => $dentro) {
            if (is_string($k)) {
                $claves[$k] = true;
            }
            $recorrer($dentro);
        }
    };
    $recorrer($t);

    expect(array_intersect(array_keys($claves), $prohibidas))->toBe([]);
});
