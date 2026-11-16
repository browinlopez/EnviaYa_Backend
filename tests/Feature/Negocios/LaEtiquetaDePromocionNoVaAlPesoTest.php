<?php

declare(strict_types=1);

use App\Models\Business;
use App\Models\Promotion;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * LA ETIQUETA DE PROMOCIÓN NO VA SOBRE LO QUE SE VENDE AL PESO.
 *
 * La ficha del negocio pinta una etiqueta de promoción en cada producto que
 * alcance una promoción vigente, y **excluye a propósito lo que se vende al
 * peso**. El motivo está escrito en el controlador: el motor de descuentos no
 * aplica promociones a esas líneas —un «lleva 3, paga 2» sobre 1,18 kg no
 * está definido— así que la etiqueta prometería una rebaja que el carrito no
 * va a dar, y el cliente llega al total esperando otro número.
 *
 * ESA EXCLUSIÓN NO FUNCIONABA, y es la segunda mitad del mismo fallo que el
 * de `ElProductoSeVeDistintoSegunLaPuertaTest`. El filtro es
 * `in_array($product->pivot->sale_unit, ['kg','libra','gramo'])`, y
 * `Business::products()` no traía `sale_unit` en su `withPivot`: el pivote
 * llegaba sin la columna, la expresión veía siempre `'unidad'` y **nunca
 * excluía nada**.
 *
 * O sea que un queso en kilos con una promoción de la tienda encima salía
 * etiquetado como rebajado, y el carrito luego no rebajaba. Arreglado el 11
 * de noviembre de 2026 añadiendo las tres columnas al `withPivot`.
 *
 * Esta prueba va por HTTP y no sobre el Resource, a propósito: lo que estaba
 * roto no era la forma de la respuesta sino el DATO que la alimentaba, y eso
 * solo se ve recorriendo el camino entero —relación, pivote, filtro, JSON—.
 */
function tiendaConPromocionYUnProductoAlPeso(): array
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $comprador = User::factory()->create(['rol' => 1]);
    Sanctum::actingAs($comprador);

    DB::table('buyer')->insert([
        'user_id' => $comprador->user_id, 'qualification' => 0, 'state' => 1,
    ]);

    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId([
        'name' => 'La del queso', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');

    // Uno por unidad y uno al peso, en la MISMA tienda y con la misma
    // promoción encima: es la unica forma de ver que la exclusion distingue.
    $porUnidad = DB::table('products')->insertGetId(['name' => 'Gaseosa', 'state' => 1], 'products_id');
    $alPeso = DB::table('products')->insertGetId(['name' => 'Queso costeño', 'state' => 1], 'products_id');

    /*
     * DOS INSERCIONES Y NO UNA EN LOTE: un `insert()` con varias filas toma
     * las claves de la PRIMERA, asi que la del peso perdia `min_qty` y
     * `step_qty` y SQLite contestaba «all VALUES must have the same number of
     * terms». Con dos filas de forma distinta, el lote no sirve.
     */
    DB::table('products_business')->insert([
        'busines_id' => $negocio, 'products_id' => $porUnidad,
        'price' => 4000, 'amount' => 10, 'sale_unit' => 'unidad',
    ]);

    DB::table('products_business')->insert([
        'busines_id' => $negocio, 'products_id' => $alPeso,
        'price' => 28000, 'amount' => 1,
        'sale_unit' => 'kg', 'min_qty' => 0.25, 'step_qty' => 0.25,
    ]);

    /*
     * Una promoción de la tienda SIN productos asociados: `promoDe()` las
     * trata como que alcanzan a todo el surtido, que es el caso donde la
     * exclusión por unidad de venta es lo único que separa a los dos
     * productos.
     */
    $promo = DB::table('promotions')->insertGetId([
        'busines_id' => $negocio,
        'description' => 'Veinte por ciento en toda la tienda',
        'tipo' => Promotion::PORCENTAJE,
        'percentage_discount' => 0.20,
        'state' => Promotion::ENVIADA,
        'sent_at' => now(),
        'start_date' => now()->subDay(),
        'end_date' => now()->addDays(3),
        'recipients_count' => 0,
        'usos' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ], 'promotion_id');

    return [
        'negocio' => (int) $negocio,
        'porUnidad' => (int) $porUnidad,
        'alPeso' => (int) $alPeso,
        'promo' => (int) $promo,
    ];
}

it('el producto por unidad lleva etiqueta y el del peso NO', function () {
    $e = tiendaConPromocionYUnProductoAlPeso();

    $r = $this->postJson('/v1/businesses/show', ['busines_id' => $e['negocio']])
        ->assertOk();

    $productos = collect($r->json('products'));

    expect($productos)->toHaveCount(2);

    $unidad = $productos->firstWhere('product_id', $e['porUnidad']);
    $peso = $productos->firstWhere('product_id', $e['alPeso']);

    expect($unidad)->not->toBeNull();
    expect($peso)->not->toBeNull();

    // Primero, que el escenario de verdad distingue las dos unidades: sin
    // esto, las dos aserciones de abajo podrian pasar con los dos productos
    // en `unidad` y la prueba no habria comprobado nada.
    expect($unidad['sale_unit'])->toBe('unidad');
    expect($peso['sale_unit'])->toBe('kg');

    // Y la afirmacion: la etiqueta llega al de unidad y no al del peso.
    expect($unidad['promocion'])->not->toBeNull();
    expect($peso['promocion'])->toBeNull();
});

it('sin la unidad en el pivote, la etiqueta se colaba: esto es lo que lo impide', function () {
    /*
     * La contrapartida en el otro sentido. Si alguien quita `sale_unit` del
     * `withPivot` —o lo lee de otro sitio que no lo traiga— el filtro vuelve
     * a ver `'unidad'` y la etiqueta vuelve a colarse. Esta prueba fija el
     * dato que lo impide, no solo su efecto: el pivote tiene que llegar con
     * la unidad puesta.
     */
    $e = tiendaConPromocionYUnProductoAlPeso();

    $negocio = Business::with('products')->find($e['negocio']);
    $peso = $negocio->products->firstWhere('products_id', $e['alPeso']);

    expect($peso->pivot->sale_unit)->toBe('kg');
    expect((float) $peso->pivot->min_qty)->toBe(0.25);
    expect((float) $peso->pivot->step_qty)->toBe(0.25);
});
