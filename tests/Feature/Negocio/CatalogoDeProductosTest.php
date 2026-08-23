<?php

use App\Models\Product\Product;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * EL CATÁLOGO MAESTRO Y CÓMO LLEGA A UNA TIENDA.
 *
 * La prueba que más importa es la primera: que el tendero ya no pueda crear
 * productos escribiendo. Antes `POST /v1/negocio/productos` iba a
 * `ProductController@store`, recibía nombre y categoría en texto libre y creaba
 * una fila en `products` —el catálogo de TODA la plataforma—, mientras el
 * `update` de al lado le impedía, con razón, cambiarle una tilde a un producto
 * compartido. Se defendía una puerta y la otra estaba abierta.
 */

/* ---------------------------------------------------------------------- */

test('el tendero ya no puede crear productos escribiendo el nombre', function () {
    $t = tenderoConCatalogo();
    Sanctum::actingAs($t['user']);

    $antes = Product::count();

    /*
     * El cuerpo del alta vieja. El endpoint ya no lo entiende: pide
     * `products_id`, y sin él no pasa de la validación. Lo que se comprueba no
     * es el 422 sino que NO NACIÓ NADA en el catálogo maestro.
     */
    $this->postJson('/v1/negocio/productos', [
        'name'        => 'INVENTADO POR EL TENDERO',
        'description' => 'lo que se me ocurra',
        'category_id' => $t['categoria'],
        'price'       => 1000,
        'amount'      => 5,
    ])->assertStatus(422);

    expect(Product::count())->toBe($antes)
        ->and(Product::where('name', 'INVENTADO POR EL TENDERO')->exists())->toBeFalse();
});

test('agregar del catalogo solo toca la fila de la tienda', function () {
    $t = tenderoConCatalogo();
    $p = productoDeCatalogo('ARROZ DIANA 500G', '7702011000011', $t['categoria']);

    Sanctum::actingAs($t['user']);

    $this->postJson('/v1/negocio/productos', [
        'products_id' => $p->products_id,
        'price'       => 4800,
        'amount'      => 12,
        // Se manda a propósito: el servidor tiene que ignorarlo.
        'name'        => 'ARROZ DEL VECINO',
    ])->assertStatus(201);

    $fila = DB::table('products_business')
        ->where('busines_id', $t['mio'])->where('products_id', $p->products_id)->first();

    expect((float) $fila->price)->toBe(4800.0)
        ->and((int) $fila->amount)->toBe(12);

    // Y el nombre del catálogo quedó intacto.
    expect(Product::find($p->products_id)->name)->toBe('ARROZ DIANA 500G');
});

test('agregar dos veces actualiza y no duplica', function () {
    $t = tenderoConCatalogo();
    $p = productoDeCatalogo('PANELA', '7702011000028', $t['categoria']);

    Sanctum::actingAs($t['user']);

    $this->postJson('/v1/negocio/productos', [
        'products_id' => $p->products_id, 'price' => 3000, 'amount' => 5,
    ])->assertStatus(201);

    /*
     * Volver a agregar algo que ya se tiene es querer corregirle el precio, no
     * un error. Antes esto lo cuidaba un `exists()` en PHP; ahora lo garantiza
     * el único de la base, que es lo que hace que dos peticiones a la vez no
     * inserten dos filas.
     */
    $r = $this->postJson('/v1/negocio/productos', [
        'products_id' => $p->products_id, 'price' => 3500, 'amount' => 9,
    ])->assertOk();

    expect($r->json('actualizado'))->toBeTrue();

    $filas = DB::table('products_business')
        ->where('busines_id', $t['mio'])->where('products_id', $p->products_id)->get();

    expect($filas)->toHaveCount(1)
        ->and((float) $filas[0]->price)->toBe(3500.0);
});

test('la busqueda dice cuales ya tiene la tienda', function () {
    $t = tenderoConCatalogo();
    $tiene = productoDeCatalogo('LECHE COLANTA', '7702011000035', $t['categoria']);
    $noTiene = productoDeCatalogo('LECHE ALPINA', '7702011000042', $t['categoria']);

    enLaTienda($t['mio'], $tiene->products_id);

    Sanctum::actingAs($t['user']);

    $r = $this->getJson('/v1/negocio/catalogo?q=LECHE')->assertOk();

    $porId = collect($r->json('data'))->keyBy('products_id');

    /*
     * Sin este dato el tendero elige algo que ya tiene, el servidor responde
     * «actualizado» y él creyó que estaba agregando. Es más barato decirlo en
     * la lista.
     */
    expect($porId[$tiene->products_id]['ya_lo_tienes'])->toBeTrue()
        ->and($porId[$noTiene->products_id]['ya_lo_tienes'])->toBeFalse();
});

test('el codigo de barras encuentra el producto exacto', function () {
    $t = tenderoConCatalogo();
    productoDeCatalogo('ATUN VAN CAMPS', '7702011000059', $t['categoria']);

    Sanctum::actingAs($t['user']);

    $r = $this->getJson('/v1/negocio/catalogo/codigo/7702011000059')->assertOk();

    expect($r->json('data.name'))->toBe('ATUN VAN CAMPS')
        ->and($r->json('data.es_borrador'))->toBeFalse();
});

test('un codigo que no existe en ninguna parte responde 404 con el codigo', function () {
    $t = tenderoConCatalogo();

    // Sin fuente externa que responda, no hay borrador que ofrecer.
    Http::fake(['world.openfoodfacts.org/*' => Http::response(['status' => 0], 200)]);

    Sanctum::actingAs($t['user']);

    $r = $this->getJson('/v1/negocio/catalogo/codigo/7702011999999')->assertStatus(404);

    // El código vuelve limpio para que el formulario de alta lo traiga puesto.
    expect($r->json('barcode'))->toBe('7702011999999');
});

test('un codigo desconocido llega como borrador de la fuente externa', function () {
    $t = tenderoConCatalogo();

    Http::fake([
        'world.openfoodfacts.org/*' => Http::response([
            'status'  => 1,
            'product' => [
                'product_name_es' => 'Gaseosa Manzana',
                'brands'          => 'Postobón, Postobon',
                'quantity'        => '400 ml',
                'image_front_url' => 'https://ejemplo/img.jpg',
            ],
        ], 200),
    ]);

    Sanctum::actingAs($t['user']);

    $r = $this->getJson('/v1/negocio/catalogo/codigo/7702011000066')->assertOk();

    /*
     * Llega como BORRADOR y no como producto: una fuente ajena puede traer el
     * nombre en otro idioma o de otra presentación, y meterlo a ciegas ensucia
     * el catálogo igual que el texto libre que se acaba de cerrar.
     */
    expect($r->json('data.products_id'))->toBeNull()
        ->and($r->json('data.es_borrador'))->toBeTrue()
        // El tamaño se pega al nombre: en el estante hay tres presentaciones.
        ->and($r->json('data.name'))->toBe('Gaseosa Manzana 400 ml')
        ->and($r->json('data.brand'))->toBe('Postobón');

    // Y no se guardó nada todavía.
    expect(Product::where('barcode', '7702011000066')->exists())->toBeFalse();
});

test('proponer un codigo que ya existe devuelve el que hay', function () {
    $t = tenderoConCatalogo();
    $ya = productoDeCatalogo('SAL REFISAL', '7702011000073', $t['categoria']);

    Sanctum::actingAs($t['user']);

    $r = $this->postJson('/v1/negocio/catalogo/proponer', [
        'name'        => 'SAL DE COCINA',
        'barcode'     => '7702011000073',
        'category_id' => $t['categoria'],
    ])->assertStatus(201);

    /*
     * Dos personas escaneando el mismo código en dos tiendas a la vez: gana el
     * que llegó primero y el segundo se lleva el mismo producto. Crear otra
     * fila con el mismo código es justo lo que el único impide.
     */
    expect($r->json('data.products_id'))->toBe((int) $ya->products_id)
        ->and(Product::where('barcode', '7702011000073')->count())->toBe(1);
});

test('lo que propone un tendero queda marcado', function () {
    $t = tenderoConCatalogo();
    Sanctum::actingAs($t['user']);

    $this->postJson('/v1/negocio/catalogo/proponer', [
        'name'        => 'AREPA DE HUEVO',
        'barcode'     => '7702011000080',
        'category_id' => $t['categoria'],
    ])->assertStatus(201);

    // Entra directo —una cola que nadie atiende deja al tendero esperando—
    // pero queda de dónde salió para poder auditarlo después.
    expect(Product::where('barcode', '7702011000080')->value('origen'))->toBe('tendero');
});

test('quitar un producto lo saca de la tienda pero no del catalogo', function () {
    $t = tenderoConCatalogo();
    $p = productoDeCatalogo('GALLETAS FESTIVAL', '7702011000097', $t['categoria']);

    enLaTienda($t['mio'], $p->products_id);
    enLaTienda($t['vecino'], $p->products_id);

    Sanctum::actingAs($t['user']);

    $this->deleteJson("/v1/negocio/productos/{$p->products_id}")->assertOk();

    // «Yo ya no vendo esto» no es «esto no existe», y solo la primera es
    // decisión suya.
    expect(DB::table('products_business')->where('busines_id', $t['mio'])->count())->toBe(0)
        ->and(DB::table('products_business')->where('busines_id', $t['vecino'])->count())->toBe(1)
        ->and(Product::find($p->products_id))->not->toBeNull();
});

test('no se puede quitar un producto de otra tienda', function () {
    $t = tenderoConCatalogo();
    $p = productoDeCatalogo('DEL VECINO', '7702011000103', $t['categoria']);

    enLaTienda($t['vecino'], $p->products_id);

    Sanctum::actingAs($t['user']);

    // 404 y no 403: confirmar que existe pero es de otro ya dice algo del
    // catálogo del vecino.
    $this->deleteJson("/v1/negocio/productos/{$p->products_id}")->assertStatus(404);

    expect(DB::table('products_business')->where('busines_id', $t['vecino'])->count())->toBe(1);
});

test('los precios se cambian en bloque y los ajenos se ignoran', function () {
    $t = tenderoConCatalogo();
    $mio = productoDeCatalogo('MIO', '7702011000110', $t['categoria']);
    $ajeno = productoDeCatalogo('AJENO', '7702011000127', $t['categoria']);

    enLaTienda($t['mio'], $mio->products_id, 1000, 2);
    enLaTienda($t['vecino'], $ajeno->products_id, 9999, 9);

    Sanctum::actingAs($t['user']);

    $r = $this->putJson('/v1/negocio/productos/precios', [
        'cambios' => [
            ['products_id' => $mio->products_id, 'price' => 1500, 'amount' => 7],
            ['products_id' => $ajeno->products_id, 'price' => 1],
        ],
    ])->assertOk();

    /*
     * El id ajeno se informa en vez de reventar la petición entera: con una
     * lista larga, uno equivocado no puede tirar abajo los otros cambios.
     */
    expect($r->json('actualizados'))->toBe(1)
        ->and($r->json('ignorados'))->toBe([(int) $ajeno->products_id]);

    expect((float) DB::table('products_business')->where('busines_id', $t['mio'])->value('price'))->toBe(1500.0)
        ->and((float) DB::table('products_business')->where('busines_id', $t['vecino'])->value('price'))->toBe(9999.0);
});

test('copiar el surtido de otra tienda no copia los precios', function () {
    $t = tenderoConCatalogo();
    $a = productoDeCatalogo('UNO', '7702011000134', $t['categoria']);
    $b = productoDeCatalogo('DOS', '7702011000141', $t['categoria']);

    enLaTienda($t['vecino'], $a->products_id, 7000, 4);
    enLaTienda($t['vecino'], $b->products_id, 8000, 6);
    enLaTienda($t['mio'], $a->products_id, 6500, 2); // este ya lo tenía

    Sanctum::actingAs($t['user']);

    $r = $this->postJson('/v1/negocio/productos/copiar', ['desde' => $t['vecino']])->assertOk();

    expect($r->json('copiados'))->toBe(1)
        ->and($r->json('ya_tenia'))->toBe(1);

    /*
     * Se copia QUÉ vende, no A CUÁNTO. El precio es lo único que de verdad
     * distingue a una tienda de la de al lado, y copiarlo hace que anuncie un
     * número que no va a respetar.
     */
    expect((float) DB::table('products_business')
        ->where('busines_id', $t['mio'])->where('products_id', $b->products_id)->value('price'))->toBe(0.0);

    // Y el que ya tenía conservó el suyo.
    expect((float) DB::table('products_business')
        ->where('busines_id', $t['mio'])->where('products_id', $a->products_id)->value('price'))->toBe(6500.0);
});

test('un producto sin precio no se le ofrece al comprador', function () {
    $t = tenderoConCatalogo();
    $conPrecio = productoDeCatalogo('SE VENDE', '7702011000158', $t['categoria']);
    $sinPrecio = productoDeCatalogo('COPIADO SIN PRECIO', '7702011000165', $t['categoria']);

    enLaTienda($t['mio'], $conPrecio->products_id, 5000, 3);
    enLaTienda($t['mio'], $sinPrecio->products_id, 0, 0);

    Sanctum::actingAs($t['user']);

    $r = $this->postJson("/v1/businesses/show", ["busines_id" => $t["mio"]])->assertOk();

    $nombres = collect($r->json('products'))->pluck('name')->all();

    /*
     * Copiar deja los productos en cero a propósito. Si esos cero llegaran al
     * comprador, la tienda estaría anunciando productos que no puede despachar.
     */
    expect($nombres)->toContain('SE VENDE')
        ->and($nombres)->not->toContain('COPIADO SIN PRECIO');
});
