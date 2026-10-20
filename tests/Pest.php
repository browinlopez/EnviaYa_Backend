<?php

use App\Models\Product\CatalogUpload;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/*
 * Un tendero con su tienda, la del vecino y una categoria.
 *
 * Vive aca y no en un archivo de pruebas porque lo usan dos —el catalogo y la
 * carga masiva— y Pest solo carga los archivos que va a correr: con el helper
 * dentro de uno de ellos, correr el otro solo se cae con
 * `Call to undefined function`.
 */
function tenderoConCatalogo(): array
{
    \App\Models\Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);

    $user = \App\Models\User::factory()->create(['rol' => 2]);

    $ownerId = \Illuminate\Support\Facades\DB::table('owner')->insertGetId(['user_id' => $user->user_id, 'state' => 1], 'owner_id');

    $tipo = \Illuminate\Support\Facades\DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');

    $mio = \Illuminate\Support\Facades\DB::table('business')->insertGetId([
        'name' => 'La Esquina', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');

    \Illuminate\Support\Facades\DB::table('owner_busines')->insert(['owner_id' => $ownerId, 'busines_id' => $mio, 'state' => 1]);

    $vecino = \Illuminate\Support\Facades\DB::table('business')->insertGetId([
        'name' => 'La de Enfrente', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');

    $categoria = \Illuminate\Support\Facades\DB::table('category')->insertGetId(['name' => 'Abarrotes'], 'category_id');

    return compact('user', 'mio', 'vecino', 'categoria');
}

function productoDeCatalogo(string $nombre, ?string $barcode = null, ?int $categoria = null): \App\Models\Product\Product
{
    return \App\Models\Product\Product::create([
        'name' => $nombre, 'barcode' => $barcode, 'category_id' => $categoria,
        'state' => 1, 'origen' => 'equipo',
    ]);
}

function enLaTienda(int $businessId, int $productId, float $precio = 5000, int $cantidad = 3): void
{
    \Illuminate\Support\Facades\DB::table('products_business')->insert([
        'busines_id' => $businessId, 'products_id' => $productId,
        'price' => $precio, 'amount' => $cantidad, 'qualification' => 0,
    ]);
}

/*
 * Un pedido aceptado, su tienda, su dueno y un domiciliario con cupo.
 *
 * Compartido entre el tope de efectivo y el candado del pedido. Vive aca
 * porque Pest solo carga los archivos que va a correr: con el helper dentro de
 * uno de ellos, correr el otro solo se cae con Call to undefined function.
 */
function escenarioDeTope(?float $tope, int $metodo = 1, int $total = 60000): array
{
    foreach ([1 => 'comprador', 2 => 'tendero', 3 => 'domiciliario'] as $id => $nombre) {
        \App\Models\Rol::firstOrCreate(['rol_id' => $id], ['name' => $nombre, 'guard_name' => 'web']);
    }

    // `orderssales.methods_id` es clave foránea: sin la fila, el insert falla.
    foreach ([1 => 'Efectivo', 2 => 'Tarjeta'] as $id => $nombre) {
        \Illuminate\Support\Facades\DB::table('payment_methods')->insertOrIgnore([
            'methods_id' => $id, 'name' => $nombre, 'state' => 1,
        ]);
    }

    $repartidor = \App\Models\User::factory()->create(['rol' => 3]);
    $domiId = \Illuminate\Support\Facades\DB::table('domiciliary')->insertGetId([
        'user_id' => $repartidor->user_id, 'available' => 1,
        'qualification' => 0, 'state' => 1,
    ], 'domiciliary_id');

    $tendero = \App\Models\User::factory()->create(['rol' => 2]);

    // `business.type` es clave foránea a `category_business`.
    $tipo = \Illuminate\Support\Facades\DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');

    $businessId = \Illuminate\Support\Facades\DB::table('business')->insertGetId([
        'name' => 'Tienda', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
        'max_courier_cash' => $tope,
    ], 'busines_id');

    $ownerId = \Illuminate\Support\Facades\DB::table('owner')->insertGetId([
        'user_id' => $tendero->user_id, 'state' => 1,
    ], 'owner_id');

    \Illuminate\Support\Facades\DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $businessId, 'state' => 1,
    ]);

    \Illuminate\Support\Facades\DB::table('business_domiciliary')->insert([
        'busines_id' => $businessId, 'domiciliary_id' => $domiId, 'state' => 1,
    ]);

    $comprador = \App\Models\User::factory()->create(['rol' => 1]);
    $buyerId = \Illuminate\Support\Facades\DB::table('buyer')->insertGetId([
        'user_id' => $comprador->user_id, 'qualification' => 0, 'state' => 1,
    ]);

    // Aceptado y esperando a que alguien lo lleve.
    $orderId = \Illuminate\Support\Facades\DB::table('orderssales')->insertGetId([
        'buyer_id' => $buyerId, 'busines_id' => $businessId,
        'methods_id' => $metodo,
        'subtotal' => $total - 2000, 'domicilio' => 2000, 'total' => $total,
        'domiciliary_fee' => 500, 'sale_date' => now(),
        'state' => 2, 'payment_state' => $metodo === 1 ? 'pending_cash' : 'paid',
    ], 'orderSales_id');

    return compact('repartidor', 'domiId', 'tendero', 'businessId', 'orderId');
}

/**
 * Un .xls BIFF2, armado a mano.
 *
 * No hay librería en PHP que lo escriba —PhpSpreadsheet ni siquiera sabe
 * leerlo, que es el motivo de que exista `LectorDeArchivoDeCatalogo`— así que
 * se construyen los bytes. El formato es una cadena de registros: tipo (2),
 * largo (2) y cuerpo. Una celda lleva fila (2), columna (2), tres bytes de
 * formato y el valor.
 */
function archivoBiff2(array $encabezados, array $filas): UploadedFile
{
    $texto = function (int $fila, int $col, string $v): string {
        /*
         * A CP1252, que es lo que de verdad lleva un archivo BIFF2: el
         * formato es de 1987 y anterior a Unicode. Escribirlo en UTF-8
         * haria una prueba que pasa contra un archivo que no existe, y
         * el lector —que convierte DESDE CP1252— doblaria la Ñ.
         */
        $v = mb_convert_encoding($v, 'CP1252', 'UTF-8');

        $cuerpo = pack('vv', $fila, $col) . "\x00\x00\x00" . chr(strlen($v)) . $v;

        return pack('vv', 0x04, strlen($cuerpo)) . $cuerpo;
    };

    $numero = function (int $fila, int $col, float $v): string {
        $cuerpo = pack('vv', $fila, $col) . "\x00\x00\x00" . pack('d', $v);

        return pack('vv', 0x03, strlen($cuerpo)) . $cuerpo;
    };

    // BOF: lo primero de todo archivo BIFF2, y lo que lo identifica.
    $bytes = "\x09\x00\x04\x00" . pack('vv', 0, 0x10);

    foreach ($encabezados as $c => $h) {
        $bytes .= $texto(0, $c, $h);
    }

    foreach ($filas as $f => $valores) {
        foreach ($valores as $c => $v) {
            $bytes .= (is_int($v) || is_float($v))
                ? $numero($f + 1, $c, (float) $v)
                : $texto($f + 1, $c, (string) $v);
        }
    }

    $bytes .= pack('vv', 0x0A, 0);  // EOF

    return UploadedFile::fake()->createWithContent('ARTICULOS.XLS', $bytes);
}

/** Sube el archivo y devuelve el expediente ya procesado. */
function cargarDesdePdv(array $t, UploadedFile $archivo, ?int $categoria = null): CatalogUpload
{
    Sanctum::actingAs($t['user']);

    $cuerpo = ['archivo' => $archivo];

    if ($categoria !== null) {
        $cuerpo['categoria_id'] = $categoria;
    }

    $r = test()->post('/v1/negocio/productos/cargas', $cuerpo)->assertStatus(202);

    return CatalogUpload::find($r->json('data.catalog_upload_id'))->fresh();
}


/*
 * UN PEDIDO MÍNIMO: comprador, negocio, producto, dirección y método.
 *
 * Vivía dentro de `OrderCouponTest.php` y se mudó acá cuando las promociones
 * necesitaron lo mismo. Mismo motivo que los de arriba: Pest solo carga los
 * archivos que va a correr, así que un ayudante compartido metido dentro de un
 * archivo de pruebas hace que el OTRO se caiga con `Call to undefined
 * function` en cuanto se corren por separado.
 */
function escenarioPedido(): array
{
    \App\Models\Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $user = \App\Models\User::factory()->create(['rol' => 1]);
    Sanctum::actingAs($user);

    $buyerId = \Illuminate\Support\Facades\DB::table('buyer')->insertGetId([
        'user_id'            => $user->user_id,
        'qualification'      => 0,
        'belongs_to_complex' => 0,
        'state'              => 1,
    ]);

    $businessId = \Illuminate\Support\Facades\DB::table('business')->insertGetId([
        'name'          => 'Tienda del Pedido',
        'qualification' => 0,
        'state'         => 1,
    ]);

    $productId = \Illuminate\Support\Facades\DB::table('products')->insertGetId([
        'name'  => 'Arroz',
        'state' => 1,
    ], 'products_id');

    // El precio SIEMPRE sale de acá, nunca del cliente. La tabla pivote se
    // llama `products_business` (en plural), no `product_business`.
    \Illuminate\Support\Facades\DB::table('products_business')->insert([
        'busines_id'  => $businessId,
        'products_id' => $productId,
        'price'       => 10000,
        'amount'      => 100,
    ]);

    $addressId = \Illuminate\Support\Facades\DB::table('user_address')->insertGetId([
        'user_id' => $user->user_id,
        'address' => 'Carrera 1 # 2-3',
        'state'   => 1,
    ]);

    /*
     * EL EFECTIVO, Y CON SU NÚMERO PUESTO A MANO.
     *
     * Antes se dejaba al autoincremento y salía el id que saliera. Daba igual
     * mientras solo importara que NO fuera 2 ni 5 —los de Bold—, pero el
     * efectivo no es «cualquier método que no sea de pasarela»: es el 1, y hay
     * código que lo comprueba por su número (`PagoEnLinea::CONTRA_ENTREGA`).
     * Con un id cualquiera, una prueba de algo propio del efectivo pasa en
     * verde sin haber ejercitado el camino del efectivo.
     */
    \Illuminate\Support\Facades\DB::table('payment_methods')->insertOrIgnore([
        'methods_id' => 1,
        'name'       => 'Efectivo',
        'state'      => 1,
    ]);

    $methodId = 1;

    return compact('user', 'buyerId', 'businessId', 'productId', 'addressId', 'methodId');
}

function cuerpoPedido(array $e, array $extra = []): array
{
    return array_merge([
        'user_id'    => $e['user']->user_id,
        'busines_id' => $e['businessId'],
        'address_id' => $e['addressId'],
        'methods_id' => $e['methodId'],
        'products'   => [['product_id' => $e['productId'], 'amount' => 3]],
    ], $extra);
}
