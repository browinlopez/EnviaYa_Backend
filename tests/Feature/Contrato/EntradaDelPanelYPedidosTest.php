<?php

/**
 * LA ENTRADA DE CINCO ENDPOINTS QUE NO TENÍAN NINGUNA PRUEBA.
 *
 * Se escriben ANTES de mover su validación a un `FormRequest`, que es el
 * acuerdo: en un endpoint sin red, extraer una regla significa que un
 * `required` que se vuelva `nullable` por descuido lo descubre un tendero y
 * no la suite.
 *
 * No prueban lo que hace cada endpoint: prueban QUÉ ACEPTA. Qué campos son
 * obligatorios, qué valores se rechazan y con qué forma sale el error. Es lo
 * único que el cambio puede estropear.
 *
 * Los cinco: cancelar un pedido desde el panel, subir un Excel de catálogo,
 * cambiar el logo, registrar la posición del domiciliario y mover el estado
 * de un pedido.
 */

use App\Models\Rol;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/** Una tienda con su dueño, y un pedido suyo si se pide. */
function tiendaParaContrato(?int $estadoDelPedido = null): array
{
    foreach ([1 => 'comprador', 2 => 'tendero', 3 => 'domiciliario'] as $id => $n) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $n, 'guard_name' => 'web']);
    }

    $tendero = User::factory()->create(['rol' => 2]);
    $ownerId = DB::table('owner')->insertGetId(
        ['user_id' => $tendero->user_id, 'state' => 1], 'owner_id',
    );
    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId([
        'name' => 'Tienda del contrato', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');
    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $negocio, 'state' => 1,
    ]);
    DB::table('payment_methods')->insertOrIgnore([
        'methods_id' => 1, 'name' => 'Efectivo', 'state' => 1,
    ]);

    $pedido = null;

    if ($estadoDelPedido !== null) {
        $comprador = User::factory()->create(['rol' => 1]);
        $buyer = DB::table('buyer')->insertGetId([
            'user_id' => $comprador->user_id, 'qualification' => 0, 'state' => 1,
        ]);
        $pedido = DB::table('orderssales')->insertGetId([
            'buyer_id' => $buyer, 'busines_id' => $negocio,
            'subtotal' => 20000, 'domicilio' => 3000, 'total' => 23000,
            'sale_date' => now(), 'state' => $estadoDelPedido,
            'payment_state' => 'pending', 'methods_id' => 1,
        ], 'orderSales_id');
    }

    Sanctum::actingAs($tendero);

    return compact('tendero', 'negocio', 'pedido');
}

/* ------------------------------------------------------------------ */
/* PUT /v1/negocio/pedidos/{id}/cancelar                               */
/* ------------------------------------------------------------------ */

test('cancelar un pedido exige un motivo', function () {
    // El motivo NO es burocracia: es lo único que el cliente va a leer.
    $t = tiendaParaContrato(1);

    $this->putJson("/v1/negocio/pedidos/{$t['pedido']}/cancelar", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['motivo']);
});

test('un motivo de dos letras no es un motivo', function () {
    $t = tiendaParaContrato(1);

    $this->putJson("/v1/negocio/pedidos/{$t['pedido']}/cancelar", ['motivo' => 'ya'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['motivo']);
});

test('un motivo de mas de 500 caracteres se rechaza', function () {
    $t = tiendaParaContrato(1);

    $this->putJson("/v1/negocio/pedidos/{$t['pedido']}/cancelar", [
        'motivo' => str_repeat('a', 501),
    ])->assertStatus(422)->assertJsonValidationErrors(['motivo']);
});

/* ------------------------------------------------------------------ */
/* POST /v1/negocio/productos/cargas                                   */
/* ------------------------------------------------------------------ */

test('subir un catalogo exige el archivo', function () {
    tiendaParaContrato();

    $this->postJson('/v1/negocio/productos/cargas', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['archivo']);
});

test('el catalogo se valida por EXTENSION y no por tipo adivinado', function () {
    /*
     * `extensions` y no `mimes`, y no es un descuido: el .xls que exportan
     * los puntos de venta de las tiendas es BIFF2, de 1987, y se adivina como
     * `application/octet-stream`. Con `mimes` se rechazaba el archivo que la
     * mitad de los tenderos tiene.
     */
    Storage::fake('local');
    tiendaParaContrato();

    $this->postJson('/v1/negocio/productos/cargas', [
        'archivo' => UploadedFile::fake()->create('catalogo.pdf', 10),
    ])->assertStatus(422)->assertJsonValidationErrors(['archivo']);
});

test('un catalogo de mas de 10 MB se rechaza', function () {
    Storage::fake('local');
    tiendaParaContrato();

    $this->postJson('/v1/negocio/productos/cargas', [
        'archivo' => UploadedFile::fake()->create('catalogo.xlsx', 10241),
    ])->assertStatus(422)->assertJsonValidationErrors(['archivo']);
});

/* ------------------------------------------------------------------ */
/* POST /v1/negocio/me/logo                                            */
/* ------------------------------------------------------------------ */

test('el logo es obligatorio y lo dice en castellano', function () {
    tiendaParaContrato();

    $this->postJson('/v1/negocio/me/logo', [])
        ->assertStatus(422)
        ->assertJsonPath('errors.logo.0', 'Elige la imagen del logo.');
});

test('un PDF no sirve de logo', function () {
    Storage::fake('public');
    tiendaParaContrato();

    $this->postJson('/v1/negocio/me/logo', [
        'logo' => UploadedFile::fake()->create('logo.pdf', 10),
    ])->assertStatus(422)->assertJsonValidationErrors(['logo']);
});

/* ------------------------------------------------------------------ */
/* POST /v1/orders/geolocation                                         */
/* ------------------------------------------------------------------ */

test('la posicion del domiciliario exige pedido, domiciliario y coordenadas', function () {
    $t = tiendaParaContrato();

    $this->postJson('/v1/orders/geolocation', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['domiciliary_id', 'orderSales_id', 'latitude', 'longitude']);
});

test('una posicion con ids que no existen se rechaza', function () {
    // `exists` y no solo `integer`: una posición atada a un pedido que no
    // existe es una fila que nadie va a poder leer nunca.
    tiendaParaContrato();

    $this->postJson('/v1/orders/geolocation', [
        'domiciliary_id' => 999999,
        'orderSales_id' => 999999,
        'latitude' => 10.98,
        'longitude' => -74.79,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['domiciliary_id', 'orderSales_id']);
});

test('una latitud que no es un numero se rechaza', function () {
    tiendaParaContrato();

    $this->postJson('/v1/orders/geolocation', [
        'domiciliary_id' => 1, 'orderSales_id' => 1,
        'latitude' => 'por ahi', 'longitude' => -74.79,
    ])->assertStatus(422)->assertJsonValidationErrors(['latitude']);
});

/* ------------------------------------------------------------------ */
/* PUT /v1/negocio/pedidos/estado                                      */
/* ------------------------------------------------------------------ */

test('mover un pedido exige su id y el estado', function () {
    tiendaParaContrato();

    $this->putJson('/v1/negocio/pedidos/estado', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['order_id', 'state']);
});

test('solo se puede mover a preparando, en camino o entregado', function () {
    /*
     * `in:2,3,4` y no cualquier entero. El 1 es «sin aceptar» —volver atrás
     * no es mover, es deshacer— y el 5 es cancelado, que tiene su propia
     * puerta porque devuelve inventario, cupo de fiado y usos de promoción.
     */
    $t = tiendaParaContrato(1);

    foreach ([1, 5, 9] as $estado) {
        $this->putJson('/v1/negocio/pedidos/estado', [
            'order_id' => $t['pedido'], 'state' => $estado,
        ])->assertStatus(422)->assertJsonValidationErrors(['state']);
    }
});

test('un user_id que no existe se rechaza al mover el pedido', function () {
    // Es a quién se le despacha: un id inventado dejaría el pedido en camino
    // sin nadie que lo lleve.
    $t = tiendaParaContrato(2);

    $this->putJson('/v1/negocio/pedidos/estado', [
        'order_id' => $t['pedido'], 'state' => 3, 'user_id' => 999999,
    ])->assertStatus(422)->assertJsonValidationErrors(['user_id']);
});
