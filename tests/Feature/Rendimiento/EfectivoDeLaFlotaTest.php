<?php

use App\Models\Operacion\CashDeposit;
use App\Models\Operacion\CashMovement;
use App\Models\Rol;
use App\Models\User;
use App\Services\CustodiaDeEfectivo;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * EL EFECTIVO DE LA FLOTA: LOS MISMOS NÚMEROS, SIN UNA CONSULTA POR CABEZA
 *
 * `GET /v1/negocio/efectivo` recorría la flota llamando a `saldo()` y a
 * `pendienteConLaTienda()` por cada domiciliario, y el segundo hace dos
 * consultas más y vuelve a llamar al primero. **Cuatro por cabeza.** Medido
 * contra la base de desarrollo: 50 consultas para una tienda con diez
 * domiciliarios, de las que 42 eran tres líneas repitiéndose. Una tienda con
 * cuarenta haría ciento ochenta.
 *
 * Lo peligroso de este arreglo no es el rendimiento: es que el número que sale
 * es **dinero que un domiciliario le debe a una tienda**. Si el cálculo por
 * lotes se separa aunque sea en un céntimo del de uno en uno, la pantalla del
 * tendero y la del domiciliario dejan de coincidir y alguien discute en el
 * mostrador.
 *
 * Por eso la prueba que vale no es la del número de consultas: es la que
 * compara **lote contra uno a uno** sobre un escenario con las tres cosas que
 * hacen interesante el reparto —cobros de dos tiendas, una entrega en
 * mostrador y una consignación a la plataforma, que se reparte entre lo que en
 * ESE momento estaba pendiente—.
 */
function flotaConEfectivo(int $cuantos): array
{
    foreach ([1 => 'comprador', 2 => 'tendero', 3 => 'domiciliario'] as $id => $n) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $n, 'guard_name' => 'web']);
    }

    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');

    $mia = DB::table('business')->insertGetId([
        'name' => 'La mia', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');

    $vecina = DB::table('business')->insertGetId([
        'name' => 'La vecina', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');

    $tendero = User::factory()->create(['rol' => 2]);
    $ownerId = DB::table('owner')->insertGetId(['user_id' => $tendero->user_id, 'state' => 1], 'owner_id');
    DB::table('owner_busines')->insert(['owner_id' => $ownerId, 'busines_id' => $mia, 'state' => 1]);

    $comprador = User::factory()->create(['rol' => 1]);
    $buyerId = DB::table('buyer')->insertGetId([
        'user_id' => $comprador->user_id, 'qualification' => 0, 'state' => 1,
    ], 'buyer_id');

    DB::table('payment_methods')->insertOrIgnore([
        'methods_id' => 1, 'name' => 'Efectivo', 'state' => 1,
    ]);

    $ids = [];

    for ($i = 0; $i < $cuantos; $i++) {
        $u = User::factory()->create(['rol' => 3]);
        $did = DB::table('domiciliary')->insertGetId([
            'user_id' => $u->user_id, 'available' => 1, 'qualification' => 0, 'state' => 1,
        ], 'domiciliary_id');

        DB::table('business_domiciliary')->insert([
            'busines_id' => $mia, 'domiciliary_id' => $did, 'state' => 1,
        ]);

        // Un cobro en cada tienda, para que el reparto tenga algo que repartir.
        foreach ([[$mia, 40000], [$vecina, 25000]] as [$negocio, $monto]) {
            $pedido = DB::table('orderssales')->insertGetId([
                'buyer_id' => $buyerId, 'busines_id' => $negocio, 'methods_id' => 1,
                'domiciliary_id' => $did, 'subtotal' => $monto, 'domicilio' => 0,
                'total' => $monto, 'sale_date' => now()->subDays(3), 'state' => 4,
            ], 'orderSales_id');

            CashMovement::create([
                'domiciliary_id' => $did, 'order_id' => $pedido,
                'type' => CashMovement::RECAUDO, 'amount' => $monto,
                'created_at' => now()->subDays(3),
            ]);
        }

        // Una entrega en el mostrador de LA MIA.
        CashDeposit::create([
            'domiciliary_id' => $did, 'busines_id' => $negocio = $mia,
            'canal' => CashDeposit::MOSTRADOR, 'amount' => 10000,
            'state' => CashDeposit::CONFIRMADA, 'confirmed_at' => now()->subDays(2),
        ]);

        // Y una consignación a la plataforma, que se reparte entre las dos.
        CashDeposit::create([
            'domiciliary_id' => $did, 'busines_id' => null,
            'canal' => CashDeposit::CONSIGNACION, 'amount' => 15000,
            'state' => CashDeposit::CONFIRMADA, 'confirmed_at' => now()->subDay(),
        ]);

        $ids[] = (int) $did;
    }

    return ['negocio' => (int) $mia, 'tendero' => $tendero, 'ids' => $ids];
}

it('el calculo por lotes da EXACTAMENTE lo mismo que uno a uno', function () {
    $e = flotaConEfectivo(4);
    $custodia = app(CustodiaDeEfectivo::class);

    $porLotes = $custodia->pendientesConLaTienda($e['negocio'], $e['ids']);
    $saldosPorLotes = $custodia->saldosDe($e['ids']);

    foreach ($e['ids'] as $id) {
        $unoAUno = $custodia->pendienteConLaTienda($e['negocio'], $id);
        $saldoUno = $custodia->saldo($id);

        /*
         * `toBe` y no `toEqual`: con dinero, «parecido» no vale. Y los dos
         * caminos comparten el recorrido cronológico a propósito, así que si
         * esto se rompe es porque alguien duplicó el algoritmo.
         */
        expect($porLotes[$id])->toBe($unoAUno);
        expect($saldosPorLotes[$id])->toBe($saldoUno);
    }

    // Y que no sea cero en todos, que es como esta prueba pasaría sin medir
    // nada: cero es igual a cero.
    expect(array_sum($porLotes))->toBeGreaterThan(0);
    expect(array_sum($saldosPorLotes))->toBeGreaterThan(0);
});

it('la pantalla no gasta una consulta por domiciliario', function () {
    /*
     * POR LA RUTA Y NO POR EL SERVICIO, y eso costó una vuelta.
     *
     * La primera versión medía `saldosDe()` y `pendientesConLaTienda()`
     * llamándolos directamente. Pasaba en verde, y al devolver el N+1 al
     * CONTROLADOR —que es donde estaba— **seguía pasando**: medía los métodos
     * nuevos, no la pantalla. Una prueba de rendimiento tiene que entrar por
     * donde entra la petición, o solo comprueba que lo nuevo existe.
     *
     * LA PENDIENTE, no el número: con cuatro y con doce domiciliarios el coste
     * tiene que ser el MISMO. Un tope fijo se queda viejo con cualquier
     * cambio; una pendiente de cero es la propiedad que se arregló.
     */
    $medir = function (int $cuantos) {
        $e = flotaConEfectivo($cuantos);
        Sanctum::actingAs($e['tendero']);

        // Una petición de calentamiento: `platform_settings` se cachea por
        // proceso y la primera paga una consulta que ninguna otra paga.
        test()->getJson('/v1/negocio/efectivo')->assertOk();

        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });

        test()->getJson('/v1/negocio/efectivo')->assertOk();

        return $n;
    };

    $cuatro = $medir(4);
    $doce = $medir(12);

    expect($doce)->toBe($cuatro);

    /*
     * Y pocas, no solo iguales: con el código de antes eran cuatro consultas
     * POR CABEZA, así que doce domiciliarios costaban casi cincuenta. El tope
     * deja margen para el resto de la pantalla —entregas, consignaciones,
     * tope de la tienda— sin dejar pasar un N+1.
     */
    expect($doce)->toBeLessThan(20);
});

it('la pantalla del tendero sigue devolviendo lo mismo', function () {
    $e = flotaConEfectivo(3);

    Sanctum::actingAs($e['tendero']);

    $r = $this->getJson('/v1/negocio/efectivo')->assertOk();

    $flota = $r->json('domiciliarios');

    expect($flota)->toHaveCount(3);

    /*
     * La forma de cada fila, clave por clave: es lo que lee la pantalla de
     * Efectivo del tendero y lo que un refactor de rendimiento no debe mover.
     */
    expect(array_keys($flota[0]))->toBe([
        'domiciliary_id', 'name', 'phone', 'saldo', 'pendiente', 'ultima_entrega',
    ]);

    $custodia = app(CustodiaDeEfectivo::class);

    foreach ($flota as $fila) {
        /*
         * `(float)` en el valor del JSON, no `toEqual`: al serializar, un
         * 40000.0 sale como `40000` y vuelve como entero. Es una diferencia de
         * tipo del transporte, no del numero, y aflojar la comparacion a
         * `toEqual` seria aceptar tambien una diferencia de VALOR — que es
         * justo lo que esta prueba vigila.
         */
        expect((float) $fila['pendiente'])
            ->toBe($custodia->pendienteConLaTienda($e['negocio'], (int) $fila['domiciliary_id']));
    }

    // Y ordenada por lo que más debe primero, que es para lo que sirve.
    $pendientes = array_column($flota, 'pendiente');
    $ordenados = $pendientes;
    rsort($ordenados);
    expect($pendientes)->toBe($ordenados);
});
