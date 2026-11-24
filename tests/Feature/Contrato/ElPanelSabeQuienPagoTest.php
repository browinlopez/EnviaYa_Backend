<?php

declare(strict_types=1);

use App\Models\Area;
use App\Models\Order\OrdersSales;
use App\Models\Payment\Payment;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * LA BANDERA `paid` DEL PANEL, QUE NO TENÍA NI UNA PRUEBA.
 *
 * El 14 de noviembre de 2026, al deshacer el rasgo `AyudasDeAdmin`, se midió
 * esto: poniendo `Payment::COBRADOS = ['rejected']` --es decir, haciendo que un
 * pago RECHAZADO cuente como cobrado-- **pasaban las 1356 pruebas**. La lista
 * de pedidos del panel y la pantalla de Pagos cuelgan las dos de esa condición,
 * y nada las defendía.
 *
 * No es una preferencia de estilo: `paid` es la columna por la que el panel
 * decide si a una tienda ya se le pagó. Equivocarla en un sentido es cobrar dos
 * veces y en el otro es no cobrar.
 *
 * Y es justo el tipo de agujero que esta semana se abrió sola: la condición
 * vivía repartida en un rasgo que diez clases usaban, donde no se podía ni
 * mirar de frente.
 */
function adminQuePago(): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    $user = User::factory()->create([
        'rol' => 4,
        'area_id' => Area::where('code', 'sistema')->firstOrFail()->id,
        'access_level' => Area::NIVEL_GESTOR,
    ]);

    Sanctum::actingAs($user);

    return $user;
}

/** Un pedido entregado, con el estado de pago que se le quiera poner. */
function pedidoConPago(?string $estadoDelPago): int
{
    $negocio = (int) DB::table('business')->insertGetId([
        'name' => 'La del pago', 'state' => 1, 'qualification' => 0,
    ], 'busines_id');

    $pedido = (int) DB::table('orderssales')->insertGetId([
        'busines_id' => $negocio,
        'subtotal' => 10000, 'domicilio' => 5000, 'domiciliary_fee' => 1250,
        'total' => 15000, 'currency' => 'COP',
        'sale_date' => now()->subDay(),
        'state' => OrdersSales::ENTREGADO,
        'payment_state' => 'approved',
        'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
    ], 'orderSales_id');

    // Sin fila en `payments` NO hay pago: es el caso de un pedido en efectivo
    // que todavía no se ha cobrado, y tiene que salir sin pagar.
    if ($estadoDelPago !== null) {
        DB::table('payments')->insert([
            'orderSales_id' => $pedido,
            'amount' => 15000, 'total' => 15000,
            /*
             * DOS COLUMNAS DISTINTAS, y confundirlas es lo que tenía esto en
             * rojo sobre MySQL. `payments.payment_status` es un `integer`
             * desde 2025 --1 = cobrado, 0 = no--, y `app/` solo escribe
             * enteros ahí (`$isApproved ? 1 : 0` en el webhook de Bold). La
             * cadena del proveedor vive en `status`, de 30 caracteres, que es
             * la que lee `Payment::COBRADOS`. SQLite se traga 'approved' en
             * una columna entera; MySQL la rechaza con un 1366.
             */
            'payment_status' => $estadoDelPago === 'approved' ? 1 : 0,
            'status' => $estadoDelPago,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $pedido;
}

/** La bandera `paid` de un pedido, tal como la sirve el listado del panel. */
function banderaPagada(object $test, int $pedido)
{
    $fila = collect($test->getJson('/v1/admin/orders')->assertOk()->json('data'))
        ->firstWhere('orderSales_id', $pedido);

    expect($fila)->not->toBeNull('El pedido no salió en el listado del panel.');

    return $fila['paid'];
}

it('un pago aprobado sale como pagado, y uno rechazado no', function () {
    adminQuePago();

    $aprobado = pedidoConPago('approved');
    $rechazado = pedidoConPago('rejected');
    $sinPago = pedidoConPago(null);

    /*
     * LAS TRES JUNTAS, y es deliberado: con solo la primera, poner
     * `COBRADOS = ['approved', 'rejected']` seguiría pasando. Lo que fija la
     * condición es que el rechazado NO cuente, que es el caso que estaba
     * indefenso.
     */
    expect((int) banderaPagada($this, $aprobado))->toBe(1);
    expect((int) banderaPagada($this, $rechazado))->toBe(0);
    expect((int) banderaPagada($this, $sinPago))->toBe(0);
});

it('el filtro de "sin pago" trae los que nadie pagó', function () {
    adminQuePago();

    $aprobado = pedidoConPago('approved');
    $rechazado = pedidoConPago('rejected');

    /*
     * Este filtro cuelga de `PedidosDelPanel::pagoAprobado()`, que es la OTRA
     * mitad de la condición: una subconsulta correlacionada por el alias `o`.
     * Si el alias se rompiera, la subconsulta dejaría de correlacionarse y
     * devolvería todo o nada --un fallo que no se ve mirando el SQL--.
     */
    $ids = collect($this->getJson('/v1/admin/orders?state_group=sin_pago')->assertOk()->json('data'))
        ->pluck('orderSales_id')->all();

    expect($ids)->toContain($rechazado);
    expect($ids)->not->toContain($aprobado);
});

it('las dos constantes valen lo que valian en el rasgo', function () {
    /*
     * El valor es contrato porque las respuestas del panel cuelgan de él. Los
     * dos salen del rasgo `AyudasDeAdmin` que se deshizo: `ACTIVOS` era
     * `[1, 2, 3]` y `PAGO_OK` era `['approved', 'paid']`.
     */
    /* El 24 de noviembre de 2026 entró el 6 (DEVOLVIENDO): un pedido que
       vuelve al negocio sigue vivo --alguien lo lleva encima--, y por eso
       cuenta como activo. Cambio de contrato a propósito. */
    expect(OrdersSales::ACTIVOS)->toBe([1, 2, 3, 6]);
    expect(Payment::COBRADOS)->toBe(['approved', 'paid']);

    // Y que salgan de las constantes, no de literales que puedan quedarse
    // viejos el día que un estado cambie de número.
    expect(OrdersSales::ACTIVOS)->toBe([
        OrdersSales::NUEVO, OrdersSales::PREPARANDO, OrdersSales::EN_CAMINO, OrdersSales::DEVOLVIENDO,
    ]);
    expect(OrdersSales::ACTIVOS)->not->toContain(OrdersSales::ENTREGADO);
    expect(OrdersSales::ACTIVOS)->not->toContain(OrdersSales::CANCELADO);
});

it('no queda una segunda casa para los objetos de consulta', function () {
    /*
     * LO QUE IMPIDE QUE VUELVA. `app/Support/Consultas/` era un segundo sitio
     * donde buscar lo mismo que `app/Queries/`, y dos hogares para una cosa es
     * el defecto que se cerró: el día que alguien añada una consulta, tiene que
     * haber UN sitio evidente donde ponerla.
     */
    expect(is_dir(base_path('app/Support/Consultas')))->toBeFalse();

    /* CONTROL: que la ruta se esté comprobando de verdad y no sea un camino
       mal escrito, con el que «no existe» es verdad sin significar nada. */
    expect(is_dir(base_path('app/Queries')))->toBeTrue();
    expect(is_file(base_path('app/Queries/PedidosDelPanel.php')))->toBeTrue();
});

it('no vuelve a haber copias privadas de los pedidos activos', function () {
    /*
     * `ACTIVOS` era la DÉCIMA declaración de estados, y se encontró por
     * casualidad al mover el rasgo: la prueba de los cinco estados buscaba
     * `NUEVO|PREPARANDO|EN_CAMINO|ENTREGADO|CANCELADO` y esta se llamaba de
     * otra forma, así que pasaba por debajo.
     *
     * Se buscan las dos formas en las que vuelve: la constante privada y el
     * literal `[1, 2, 3]` suelto, que es como estaba en
     * `ConsultaDePedidosController` y en `ResumenDeArea`.
     */
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS)
    );

    $rxConst = '~(private|protected)\s+const\s+ACTIVOS\s*=~';
    $rxLiteral = '~\[\s*1\s*,\s*2\s*,\s*3\s*\]~';

    $copias = [];
    $revisados = 0;

    foreach ($it as $fichero) {
        if ($fichero->getExtension() !== 'php') {
            continue;
        }

        $revisados++;

        /*
         * SE MIRA EL CÓDIGO, NO LOS COMENTARIOS.
         *
         * La primera versión de esto señalaba a `OrdersSales` por su propia
         * cabecera, que narra la forma mala --«vivía como
         * `private const ACTIVOS = [1, 2, 3]`»-- para que se entienda qué se
         * arregló. Un detector que no sabe distinguir una advertencia de una
         * infracción obliga a borrar la advertencia, que es lo contrario de lo
         * que se quiere.
         *
         * Y de paso lo hace más estricto: una copia de verdad tampoco se puede
         * esconder dentro de un comentario.
         */
        $codigo = implode('', array_map(
            fn ($t) => is_array($t)
                ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1])
                : $t,
            token_get_all(file_get_contents($fichero->getPathname())),
        ));

        foreach (['constante privada' => $rxConst, 'literal [1, 2, 3]' => $rxLiteral] as $que => $rx) {
            if (preg_match($rx, $codigo)) {
                $copias[] = basename($fichero->getPathname()).' -> '.$que;
            }
        }
    }

    /* CONTROL: que haya leído el código. Con la carpeta vacía, «ninguna copia»
       es verdad estando ciego. */
    expect($revisados)->toBeGreaterThan(300);

    /* Y que los dos patrones reconozcan la forma mala, que es la otra mitad de
       un detector: uno que no encuentra lo que sé que está pasa en verde. */
    expect(preg_match($rxConst, 'private const ACTIVOS = [1, 2, 3];'))->toBe(1);
    expect(preg_match($rxConst, 'public const ACTIVOS = [1, 2, 3];'))->toBe(0);
    expect(preg_match($rxLiteral, "whereIn('state', [1, 2, 3])"))->toBe(1);
    expect(preg_match($rxLiteral, "whereIn('state', OrdersSales::ACTIVOS)"))->toBe(0);

    expect($copias)->toBe([]);
});
