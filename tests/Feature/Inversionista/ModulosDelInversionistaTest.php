<?php

/**
 * LOS MÓDULOS DEL INVERSIONISTA: NEGOCIOS, EXPANSIÓN, USUARIOS, OPERACIÓN,
 * INGRESOS E INFORMES.
 *
 * Un escenario sembrado a mano donde cada número se cuenta con el dedo. El 20
 * de noviembre de 2026 es «hoy»; el periodo pedido es del 1 al 20 de
 * noviembre, y el anterior, del 12 al 31 de octubre.
 *
 *  Negocios   n1 tienda Barranquilla, sin fecha de alta, con coordenadas
 *             n2 droguería Barranquilla, alta 1 oct, primera venta 11 oct
 *             n3 tienda Soledad, alta 2 nov, primera venta 4 nov
 *             n4 tienda Barranquilla, alta 10 nov, sin ventas
 *             n5 tienda cerrada (no cuenta)
 *  Compradores  b1 sin fecha, vive en Barranquilla y en un conjunto
 *               b2 alta 20 oct, vive en Soledad
 *               b3 alta 5 nov, vive en Cali (donde no hay negocios)
 *  Domiciliarios  d1 disponible (n1), d2 no disponible (n3), d3 deshabilitado
 *
 *  Pedidos del periodo, entregados y pagados:
 *    o1 b1·n1  10.000  domicilio 1.000 / repartidor 750 / comisión 270
 *                      en camino 30 min, total 50, plazo 30 → a tiempo
 *    o2 b1·n1  20.000  domicilio 2.000 / 1.500 / 540, subsidio 500
 *                      en camino 45, total 55, plazo 30 → tarde
 *    o3 b2·n2  30.000  domicilio 2.000 / 1.500 / 840
 *                      en camino 15, total 45, plazo 20 → a tiempo
 *    o4 b3·n3   5.000  recogido en el local, comisión 150 (sin tiempos)
 *  Y además: un cancelado, uno en curso y uno con el pago en línea pendiente.
 *  Periodo anterior: o9 b2·n2 40.000 (en camino 20, total 30, a tiempo).
 *  Antes de todo: o10 b2·n2 12.000 el 11 de octubre (la primera de n2).
 */

use App\Models\Rol;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;

function invModEscenario(): array
{
    Carbon::setTestNow('2026-11-20 12:00:00');

    foreach ([1 => 'comprador', 3 => 'domiciliario', 7 => 'inversionista'] as $id => $nombre) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $nombre, 'guard_name' => 'web']);
    }

    DB::table('payment_methods')->insertOrIgnore(['methods_id' => 1, 'name' => 'Efectivo', 'state' => 1]);

    $pais = DB::table('countries')->insertGetId(['name' => 'Colombia', 'iso_code' => 'CO']);
    $atl = DB::table('departments')->insertGetId(['name' => 'Atlántico', 'country_id' => $pais]);
    $valle = DB::table('departments')->insertGetId(['name' => 'Valle', 'country_id' => $pais]);
    $baq = DB::table('municipalities')->insertGetId(['name' => 'Barranquilla', 'department_id' => $atl]);
    $sol = DB::table('municipalities')->insertGetId(['name' => 'Soledad', 'department_id' => $atl]);
    $cali = DB::table('municipalities')->insertGetId(['name' => 'Cali', 'department_id' => $valle]);

    $tienda = DB::table('category_business')->insertGetId(['name' => 'Tienda']);
    $farmacia = DB::table('category_business')->insertGetId(['name' => 'Farmacia']);

    $negocio = fn (string $nombre, int $tipo, int $ciudad, ?string $alta, ?array $punto = null, int $estado = 1) => DB::table('business')->insertGetId([
        'name' => $nombre, 'qualification' => 0, 'state' => $estado, 'type' => $tipo,
        'municipality_id' => $ciudad, 'created_at' => $alta,
        'latitude' => $punto[0] ?? null, 'longitude' => $punto[1] ?? null,
        'address' => 'Carrera 50 # 70-12 Local 3',
    ], 'busines_id');

    $n1 = $negocio('Tienda La Secreta', $tienda, $baq, null, [10.991, -74.801]);
    $n2 = $negocio('Droguería Escondida', $farmacia, $baq, '2026-10-01 09:00:00', [10.995, -74.805]);
    $n3 = $negocio('Tienda de Soledad Oculta', $tienda, $sol, '2026-11-02 09:00:00');
    $negocio('Tienda Sin Estreno', $tienda, $baq, '2026-11-10 09:00:00');
    $negocio('Tienda Cerrada', $tienda, $baq, null, null, 0);

    $conjunto = DB::table('residential_complexes')->insertGetId(
        ['name' => 'Conjunto Reservado', 'state' => 1, 'municipality_id' => $baq, 'latitude' => 10.98, 'longitude' => -74.81],
        'complex_id',
    );
    DB::table('residential_complexes')->insert(['name' => 'Conjunto Inactivo', 'state' => 0, 'municipality_id' => $sol]);

    $comprador = function (string $nombre, string $correo, ?string $alta, int $ciudad) {
        $u = User::factory()->create(['name' => $nombre, 'email' => $correo, 'phone' => '3001112233', 'rol' => 1]);
        DB::table('user')->where('user_id', $u->user_id)->update(['created_at' => $alta]);
        DB::table('user_address')->insert([
            'user_id' => $u->user_id, 'address' => 'Calle Privada 9 # 8-7', 'state' => 1, 'municipality_id' => $ciudad,
        ]);

        return DB::table('buyer')->insertGetId(['user_id' => $u->user_id, 'qualification' => 0, 'state' => 1], 'buyer_id');
    };

    $b1 = $comprador('Marta Reservada', 'marta.privada@correo.co', null, $baq);
    $b2 = $comprador('Pedro Reservado', 'pedro.privado@correo.co', '2026-10-20 10:00:00', $sol);
    $b3 = $comprador('Ana Reservada', 'ana.privada@correo.co', '2026-11-05 10:00:00', $cali);
    DB::table('buyer_complex')->insert(['buyer_id' => $b1, 'complex_id' => $conjunto]);

    $domiciliario = function (string $nombre, int $disponible, int $estado, ?int $negocio) {
        $u = User::factory()->create(['name' => $nombre, 'rol' => 3, 'phone' => '3104445566']);
        $id = DB::table('domiciliary')->insertGetId([
            'user_id' => $u->user_id, 'available' => $disponible, 'state' => $estado, 'qualification' => 0,
        ], 'domiciliary_id');
        if ($negocio) {
            DB::table('business_domiciliary')->insert(['busines_id' => $negocio, 'domiciliary_id' => $id, 'state' => 1]);
        }

        return $id;
    };

    $d1 = $domiciliario('Repartidor Uno Reservado', 1, 1, $n1);
    $d2 = $domiciliario('Repartidor Dos Reservado', 0, 1, $n3);
    $domiciliario('Repartidor Tres Reservado', 1, 0, null);

    $pedido = fn (array $o) => DB::table('orderssales')->insertGetId($o + [
        'methods_id' => 1, 'discount' => 0, 'state' => 4, 'payment_state' => 'paid',
        'domicilio' => 0, 'domiciliary_fee' => 0, 'platform_fee' => 0, 'delivery_subsidy' => 0,
    ], 'orderSales_id');

    $pedido(['buyer_id' => $b1, 'busines_id' => $n1, 'domiciliary_id' => $d1, 'total' => 10000, 'subtotal' => 9000,
        'domicilio' => 1000, 'domiciliary_fee' => 750, 'platform_fee' => 270,
        'sale_date' => '2026-11-02 10:00:00', 'dispatched_at' => '2026-11-02 10:20:00', 'delivery_date' => '2026-11-02 10:50:00', 'promised_minutes' => 30]);
    $pedido(['buyer_id' => $b1, 'busines_id' => $n1, 'domiciliary_id' => $d1, 'total' => 20000, 'subtotal' => 18000,
        'domicilio' => 2000, 'domiciliary_fee' => 1500, 'platform_fee' => 540, 'delivery_subsidy' => 500,
        'sale_date' => '2026-11-05 12:00:00', 'dispatched_at' => '2026-11-05 12:10:00', 'delivery_date' => '2026-11-05 12:55:00', 'promised_minutes' => 30]);
    $pedido(['buyer_id' => $b2, 'busines_id' => $n2, 'domiciliary_id' => $d2, 'total' => 30000, 'subtotal' => 28000,
        'domicilio' => 2000, 'domiciliary_fee' => 1500, 'platform_fee' => 840,
        'sale_date' => '2026-11-06 09:00:00', 'dispatched_at' => '2026-11-06 09:30:00', 'delivery_date' => '2026-11-06 09:45:00', 'promised_minutes' => 20]);
    $pedido(['buyer_id' => $b3, 'busines_id' => $n3, 'total' => 5000, 'subtotal' => 5000, 'platform_fee' => 150, 'pickup' => 1,
        'sale_date' => '2026-11-04 08:00:00', 'delivery_date' => '2026-11-04 08:30:00']);

    // No son entregas, pero sí pedidos hechos (la base de la cancelación).
    $pedido(['buyer_id' => $b2, 'busines_id' => $n1, 'total' => 99999, 'subtotal' => 99999, 'state' => 5, 'sale_date' => '2026-11-07 12:00:00']);
    $pedido(['buyer_id' => $b1, 'busines_id' => $n2, 'total' => 88888, 'subtotal' => 88888, 'state' => 2, 'sale_date' => '2026-11-19 12:00:00']);
    $pedido(['buyer_id' => $b3, 'busines_id' => $n1, 'total' => 77777, 'subtotal' => 77777, 'payment_state' => 'pending_online', 'sale_date' => '2026-11-08 12:00:00']);

    // El periodo anterior y lo de antes.
    $pedido(['buyer_id' => $b2, 'busines_id' => $n2, 'domiciliary_id' => $d2, 'total' => 40000, 'subtotal' => 38000,
        'domicilio' => 2000, 'domiciliary_fee' => 1500, 'platform_fee' => 1140,
        'sale_date' => '2026-10-20 11:50:00', 'dispatched_at' => '2026-10-20 12:00:00', 'delivery_date' => '2026-10-20 12:20:00', 'promised_minutes' => 30]);
    $pedido(['buyer_id' => $b2, 'busines_id' => $n2, 'total' => 12000, 'subtotal' => 12000, 'platform_fee' => 360,
        'sale_date' => '2026-10-11 12:00:00']);

    Sanctum::actingAs(User::factory()->create(['rol' => 7, 'name' => 'Quien Invierte Reservado']));

    return compact('baq', 'sol', 'cali', 'tienda', 'farmacia');
}

function invModPedir(string $modulo, array $filtros = []): array
{
    $q = http_build_query(['desde' => '2026-11-01', 'hasta' => '2026-11-20'] + $filtros);

    return test()->getJson("/v1/inversionista/{$modulo}?{$q}")->assertOk()->json();
}

afterEach(fn () => Carbon::setTestNow());

test('negocios: activos, con ventas y la parte de las ventas de cada tipo', function () {
    invModEscenario();
    $n = invModPedir('negocios');

    expect($n['actividad'])->toMatchArray([
        'activos' => ['valor' => 4, 'anterior' => 2, 'variacion' => 100],
        'con_ventas' => ['valor' => 3, 'anterior' => 1, 'variacion' => 200],
        'sin_ventas' => 1,
        'porcentaje_con_ventas' => 75,
        'nuevos' => 2,
    ])->and((float) $n['actividad']['ventas_por_negocio']['valor'])->toBe(21666.67)
        ->and((float) $n['actividad']['ventas_por_negocio']['anterior'])->toBe(40000.0);

    $tipos = collect($n['por_tipo'])->keyBy('clave');
    expect((float) $tipos['tienda']['participacion_ventas'])->toBe(53.8)
        ->and((float) $tipos['drogueria']['participacion_ventas'])->toBe(46.2)
        ->and((float) $tipos['tienda']['participacion_pedidos'])->toBe(75.0);
});

test('negocios: dias del alta a la primera venta, solo con fecha de alta', function () {
    invModEscenario();
    $a = invModPedir('negocios')['arranque'];

    // n2: 1 → 11 oct = 10 días; n3: 2 → 4 nov = 2 días. n4 espera; n1 no tiene fecha.
    expect($a)->toMatchArray([
        'medidos' => 2, 'en_espera' => 1, 'sin_fecha_de_alta' => 1,
    ])->and((float) $a['promedio_dias'])->toBe(6.0)
        ->and((float) $a['mediana_dias'])->toBe(6.0)
        ->and(array_column($a['tramos'], 'negocios'))->toBe([1, 1, 0]);

    $meses = collect(invModPedir('negocios')['meses'])->keyBy('mes');
    expect($meses)->toHaveCount(12)
        ->and($meses['2026-11'])->toMatchArray(['negocios_nuevos' => 2, 'primeras_ventas' => 2, 'con_ventas' => 3])
        ->and($meses['2026-10'])->toMatchArray(['negocios_nuevos' => 1, 'primeras_ventas' => 1, 'con_ventas' => 1]);
});

test('expansion: cobertura, conjuntos, la ciudad sin oferta y el punto redondeado', function () {
    invModEscenario();
    $e = invModPedir('expansion');

    expect($e['resumen'])->toBe([
        'ciudades_con_negocios' => 2, 'ciudades_sin_oferta' => 1, 'departamentos' => 1,
        'conjuntos_activos' => 1, 'residentes_en_conjuntos' => 1,
    ]);

    $c = collect($e['por_ciudad'])->keyBy('ciudad');
    expect(array_keys($c->all()))->toBe(['Barranquilla', 'Soledad', 'Cali']);

    expect($c['Barranquilla'])->toMatchArray([
        'negocios' => 3, 'conjuntos' => 1, 'residentes' => 1, 'pedidos' => 3, 'compradores' => 1,
        'punto' => ['lat' => 10.99, 'lng' => -74.8],
    ])->and((float) $c['Barranquilla']['compradores_por_negocio'])->toBe(0.3);

    // Soledad no tiene coordenadas ni conjunto activo: no hay dónde ponerla.
    expect($c['Soledad'])->toMatchArray(['negocios' => 1, 'pedidos' => 1, 'compradores' => 1, 'punto' => null]);
    expect($c['Cali'])->toMatchArray(['negocios' => 0, 'compradores' => 1, 'compradores_por_negocio' => null]);
});

test('usuarios: activos, recompra, pedidos por comprador y conjuntos', function () {
    invModEscenario();
    $u = invModPedir('usuarios');
    $c = $u['cifras'];

    expect($c['registrados'])->toMatchArray(['valor' => 3, 'anterior' => 2])
        ->and($c['nuevos'])->toMatchArray(['valor' => 1, 'anterior' => 1])
        ->and($c['activos'])->toMatchArray(['valor' => 3, 'anterior' => 1])
        ->and((float) $c['porcentaje_activos'])->toBe(100.0)
        ->and($c['recompra']['repiten'])->toBe(1)
        ->and((float) $c['recompra']['valor'])->toBe(33.3)
        ->and((float) $c['recompra']['anterior'])->toBe(0.0)
        ->and((float) $c['pedidos_por_comprador']['valor'])->toBe(1.3)
        ->and($c['en_conjuntos']['valor'])->toBe(1)
        ->and((float) $c['en_conjuntos']['porcentaje'])->toBe(33.3);

    expect(array_column($u['frecuencia'], 'compradores'))->toBe([2, 1, 0, 0]);

    $meses = collect($u['meses'])->keyBy('mes');
    expect($meses['2026-11'])->toMatchArray(['registrados' => 3, 'nuevos' => 1, 'activos' => 3])
        ->and($meses['2026-10'])->toMatchArray(['registrados' => 2, 'nuevos' => 1, 'activos' => 1])
        ->and($meses['2026-09'])->toMatchArray(['registrados' => 1, 'activos' => 0, 'recompra' => null]);
});

test('operacion: tiempos, a tiempo, cancelados y flota', function () {
    invModEscenario();
    $o = invModPedir('operacion');
    $c = $o['cifras'];

    expect($c['entregados'])->toMatchArray(['valor' => 4, 'anterior' => 1])
        ->and((float) $c['minutos_en_camino']['valor'])->toBe(30.0)
        ->and((float) $c['minutos_en_camino']['anterior'])->toBe(20.0)
        ->and((float) $c['minutos_totales']['valor'])->toBe(50.0)
        ->and((float) $c['minutos_totales']['anterior'])->toBe(30.0)
        ->and((float) $c['a_tiempo']['valor'])->toBe(66.7)
        ->and((float) $c['a_tiempo']['anterior'])->toBe(100.0)
        ->and($c['entregas_medidas'])->toBe(3)
        // Siete pedidos hechos (de todo estado), uno cancelado.
        ->and($c['pedidos_hechos']['valor'])->toBe(7)
        ->and($c['cancelados']['valor'])->toBe(1)
        ->and((float) $c['porcentaje_cancelados']['valor'])->toBe(14.3)
        ->and((float) $c['porcentaje_cancelados']['anterior'])->toBe(0.0)
        ->and($c['domiciliarios_activos'])->toMatchArray(['valor' => 2, 'anterior' => 1])
        ->and((float) $c['pedidos_por_domiciliario']['valor'])->toBe(1.5);

    // El deshabilitado no cuenta; el que no está disponible, solo en habilitados.
    expect($o['flota'])->toBe(['habilitados' => 2, 'disponibles' => 1]);

    $nov = collect($o['meses'])->keyBy('mes')['2026-11'];
    expect($nov)->toMatchArray(['entregados' => 4, 'cancelados' => 1, 'domiciliarios_activos' => 2])
        ->and((float) $nov['minutos_en_camino'])->toBe(30.0);
});

test('operacion: con una ciudad, la flota es la de sus negocios', function () {
    $e = invModEscenario();

    expect(invModPedir('operacion', ['municipality_id' => $e['sol']])['flota'])
        ->toBe(['habilitados' => 1, 'disponibles' => 0]);
});

test('ingresos: comisiones mas margen del domicilio, sin restar dos veces el subsidio', function () {
    invModEscenario();
    $i = invModPedir('ingresos');
    $c = $i['cifras'];

    expect((float) $c['ventas']['valor'])->toBe(65000.0)
        ->and((float) $c['comisiones']['valor'])->toBe(1800.0)
        // (1.000 − 750) + (2.000 − 1.500) + (2.000 − 1.500) + 0
        ->and((float) $c['margen_domicilio']['valor'])->toBe(1250.0)
        ->and((float) $c['ingreso']['valor'])->toBe(3050.0)
        ->and((float) $c['ingreso']['anterior'])->toBe(1640.0)
        ->and((float) $c['take_rate']['valor'])->toBe(4.69)
        ->and((float) $c['take_rate']['anterior'])->toBe(4.1)
        ->and((float) $c['subsidios']['valor'])->toBe(500.0)
        ->and((float) $c['ingreso_por_pedido']['valor'])->toBe(762.5);

    $tipos = collect($i['por_tipo'])->keyBy('clave');
    // Tienda: 270 + 540 + 150 + 250 + 500 = 1.710; droguería: 840 + 500 = 1.340.
    expect((float) $tipos['tienda']['ingreso'])->toBe(1710.0)
        ->and((float) $tipos['drogueria']['ingreso'])->toBe(1340.0)
        ->and((float) $tipos['tienda']['participacion'])->toBe(56.1);

    expect((float) collect($i['meses'])->keyBy('mes')['2026-11']['ingreso'])->toBe(3050.0);
});

test('el filtro de tipo recorta ingresos y operacion', function () {
    $e = invModEscenario();

    expect((float) invModPedir('ingresos', ['tipo' => $e['farmacia']])['cifras']['ingreso']['valor'])->toBe(1340.0)
        ->and(invModPedir('operacion', ['tipo' => $e['farmacia']])['cifras']['entregados']['valor'])->toBe(1);
});

test('el informe se descarga en Excel con sus siete hojas', function () {
    invModEscenario();

    $r = $this->get('/v1/inversionista/informe?desde=2026-11-01&hasta=2026-11-20')->assertOk();

    expect($r->headers->get('content-disposition'))->toContain('informe-vecipaya-2026-11-01-a-2026-11-20.xlsx');

    $archivo = tempnam(sys_get_temp_dir(), 'inv').'.xlsx';
    file_put_contents($archivo, $r->streamedContent());
    $libro = IOFactory::load($archivo);

    expect($libro->getSheetNames())->toBe(['Resumen', 'Mes a mes', 'Negocios', 'Ciudades', 'Usuarios', 'Operación', 'Ingresos']);

    // Las cifras son las de la pantalla, y nada de una persona ni de un negocio.
    $texto = '';
    foreach ($libro->getAllSheets() as $hoja) {
        foreach ($hoja->toArray(null, false, false) as $fila) {
            $texto .= implode('|', array_map('strval', $fila))."\n";
        }
    }
    expect($texto)->toContain('65000')->toContain('3050');
    foreach (['Marta Reservada', 'marta.privada', 'Tienda La Secreta', 'Droguería Escondida', 'Calle Privada', 'Carrera 50', 'Repartidor Uno', 'Conjunto Reservado'] as $dato) {
        expect($texto)->not->toContain($dato);
    }

    @unlink($archivo);
});

test('el informe tambien sale en PDF', function () {
    invModEscenario();

    $r = $this->get('/v1/inversionista/informe?desde=2026-11-01&hasta=2026-11-20&formato=pdf')->assertOk();

    expect($r->headers->get('content-type'))->toBe('application/pdf')
        ->and($r->headers->get('content-disposition'))->toContain('informe-vecipaya-2026-11-01-a-2026-11-20.pdf')
        ->and(substr($r->getContent(), 0, 4))->toBe('%PDF');
});

test('ningun modulo trae un dato de una persona, de un negocio ni de un pedido', function (string $modulo) {
    invModEscenario();
    $datos = invModPedir($modulo);
    $crudo = json_encode($datos, JSON_UNESCAPED_UNICODE);

    foreach ([
        'Marta Reservada', 'marta.privada@correo.co', 'Pedro Reservado', 'Ana Reservada', '3001112233', '3104445566',
        'Calle Privada', 'Carrera 50', 'Quien Invierte', 'Tienda La Secreta', 'Droguería Escondida',
        'Tienda de Soledad Oculta', 'Tienda Sin Estreno', 'Repartidor Uno', 'Conjunto Reservado',
        // Las coordenadas exactas de un negocio tampoco: solo el punto redondeado de la ciudad.
        '10.991', '-74.801', '10.995', '-74.805',
    ] as $dato) {
        expect($crudo)->not->toContain($dato);
    }

    $prohibidas = [
        'name', 'email', 'phone', 'address', 'document', 'user_id', 'buyer_id', 'domiciliary_id',
        'busines_id', 'business_id', 'orderSales_id', 'order_id', 'complex_id', 'latitude', 'longitude',
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
    $recorrer($datos);

    expect(array_intersect(array_keys($claves), $prohibidas))->toBe([]);
})->with(['opciones', 'resumen', 'negocios', 'expansion', 'usuarios', 'operacion', 'ingresos']);

/**
 * El mapa: direcciones con coordenadas para b1 (dos, en la misma celda) y b2.
 * o1 y o2 (b1·n1) van a la primera dirección; se agregan dos entregas más de
 * n1 a la segunda de b1 (misma celda) y una de b2 a otra celda.
 */
function invModMapa(): array
{
    $e = invModEscenario();

    $usuario = fn (string $correo) => DB::table('user')->where('email', $correo)->value('user_id');
    $buyer = fn (string $correo) => DB::table('buyer')->where('user_id', $usuario($correo))->value('buyer_id');
    $direccion = fn (string $correo, float $lat, float $lng) => DB::table('user_address')->insertGetId([
        'user_id' => $usuario($correo), 'address' => 'Calle Privada 9 # 8-7', 'state' => 1,
        'latitude' => $lat, 'longitude' => $lng,
    ], 'address_id');

    // Las dos de Marta caen en la celda [2749, -18701] (0,004°).
    $casa = $direccion('marta.privada@correo.co', 10.99712, -74.80321);
    $oficina = $direccion('marta.privada@correo.co', 10.99788, -74.80255);
    $lejos = $direccion('pedro.privado@correo.co', 10.91234, -74.76543);

    $n1 = DB::table('business')->where('name', 'Tienda La Secreta')->value('busines_id');
    $n2 = DB::table('business')->where('name', 'Droguería Escondida')->value('busines_id');

    DB::table('orderssales')->where('busines_id', $n1)->where('state', 4)->where('payment_state', 'paid')
        ->update(['address_id' => $casa]);
    DB::table('orderssales')->where('busines_id', $n2)->where('sale_date', '2026-11-06 09:00:00')
        ->update(['address_id' => $lejos]);

    foreach (['2026-11-10 12:00:00', '2026-11-11 12:00:00'] as $fecha) {
        DB::table('orderssales')->insert([
            'buyer_id' => $buyer('marta.privada@correo.co'), 'busines_id' => $n1, 'address_id' => $oficina,
            'methods_id' => 1, 'total' => 1000, 'subtotal' => 1000, 'discount' => 0, 'state' => 4,
            'payment_state' => 'paid', 'sale_date' => $fecha,
        ]);
    }

    return $e;
}

test('expansion: el radio de entregas es el percentil 90, con cinco entregas o mas', function () {
    invModMapa();
    $c = collect(invModPedir('expansion')['por_ciudad'])->keyBy('ciudad');

    // Barranquilla: cuatro entregas cerca de n1 (~0,7 km) y una lejos (~10 km)
    // desde n2. Cinco medidas: el p90 es la quinta, la lejana.
    expect($c['Barranquilla']['entregas_medidas'])->toBe(5)
        ->and($c['Barranquilla']['radio_km'])->toBeGreaterThan(9.0)->toBeLessThan(12.0);

    // Soledad no tiene coordenadas de negocio: no hay radio que medir.
    expect($c['Soledad'])->toMatchArray(['radio_km' => null, 'entregas_medidas' => 0]);
});

test('expansion: el mapa de calor solo trae celdas de 3 pedidos o mas, por su centro', function () {
    invModMapa();
    $calor = invModPedir('expansion')['calor'];

    // La celda de Marta tiene 4 pedidos; la de Pedro, 1 (no sale).
    expect($calor['celdas'])->toBe([['lat' => 10.998, 'lng' => -74.802, 'pedidos' => 4]])
        ->and($calor['minimo_por_celda'])->toBe(3)
        ->and($calor['pedidos_ocultos'])->toBe(1);

    $crudo = json_encode(invModPedir('expansion'));
    foreach (['10.99712', '74.80321', '10.99788', '74.80255', '10.91234', '74.76543', '10.912', '-74.765'] as $coordenada) {
        expect($crudo)->not->toContain($coordenada);
    }
});

test('expansion: el mapa respeta el filtro de ciudad', function () {
    $e = invModMapa();

    expect(invModPedir('expansion', ['municipality_id' => $e['sol']])['calor']['celdas'])->toBe([]);
});
