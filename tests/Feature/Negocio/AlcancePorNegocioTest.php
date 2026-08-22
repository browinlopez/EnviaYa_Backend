<?php

use App\Models\Order\OrdersSales;
use App\Models\Product\Product;
use App\Models\Product\ProductBusiness;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * El alcance por registro del panel del tendero.
 *
 * Es el mismo problema que resolvió `complex_staff` para los conjuntos, en el
 * otro extremo del sistema: los permisos del proyecto son por módulo y nunca
 * por fila, así que `POST /v1/orders/business` con el número de otra tienda
 * devuelve sus pedidos —con nombre, teléfono y dirección de cada comprador—.
 *
 * Las pruebas que más importan son las dos que piden el negocio del vecino.
 */

function tenderoConNegocios(array $nombres): array
{
    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);

    $user = User::factory()->create(['rol' => 2]);

    $ownerId = DB::table('owner')->insertGetId([
        'user_id' => $user->user_id,
        'state'   => 1,
    ], 'owner_id');

    $negocios = [];

    // `business.type` es clave foránea a `category_business`, no un número
    // suelto: sin la fila, la inserción falla por integridad.
    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');

    foreach ($nombres as $nombre) {
        $id = DB::table('business')->insertGetId([
            'name' => $nombre, 'qualification' => 0, 'state' => 1, 'type' => $tipo,
        ], 'busines_id');

        DB::table('owner_busines')->insert([
            'owner_id' => $ownerId, 'busines_id' => $id, 'state' => 1,
        ]);

        $negocios[$nombre] = $id;
    }

    return ['user' => $user, 'negocios' => $negocios];
}

function pedidoConfirmadoEn(int $businessId, int $estado = 1): int
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $comprador = User::factory()->create(['rol' => 1]);
    $buyerId = DB::table('buyer')->insertGetId([
        'user_id' => $comprador->user_id, 'qualification' => 0, 'state' => 1,
    ]);

    return DB::table('orderssales')->insertGetId([
        'buyer_id' => $buyerId, 'busines_id' => $businessId,
        'subtotal' => 18000, 'domicilio' => 2000, 'total' => 20000,
        'sale_date' => now(), 'state' => $estado, 'payment_state' => 'paid',
    ], 'orderSales_id');
}

/* ---------------------------------------------------------------------- */

test('sin cabecera se atiende el primer negocio, y la lista trae todos', function () {
    $t = tenderoConNegocios(['Ana Bakery', 'Zeta Market']);

    Sanctum::actingAs($t['user']);

    $r = $this->getJson('/v1/negocio/me')->assertOk();

    // Alfabético, no el orden de la base: el selector los enseña en una lista
    // y una lista que cambia de orden hace que se elija el equivocado.
    expect($r->json('business.name'))->toBe('Ana Bakery')
        ->and($r->json('businesses'))->toHaveCount(2)
        ->and($r->json('role'))->toBe('tendero');
});

test('con la cabecera se cambia al otro negocio propio', function () {
    $t = tenderoConNegocios(['Ana Bakery', 'Zeta Market']);

    Sanctum::actingAs($t['user']);

    $this->withHeader('X-Negocio', (string) $t['negocios']['Zeta Market'])
        ->getJson('/v1/negocio/me')
        ->assertOk()
        ->assertJsonPath('business.name', 'Zeta Market');
});

test('pedir el negocio de otro no cae al propio en silencio: lo rechaza', function () {
    $mio   = tenderoConNegocios(['La Mía']);
    $ajeno = tenderoConNegocios(['La Ajena']);

    Sanctum::actingAs($mio['user']);

    /*
     * Rechazar y no caer al primero es la mitad de la prueba: caer en silencio
     * enseñaría los datos de su propia tienda bajo el nombre de la otra, y
     * nadie se enteraría de que el selector no hizo nada.
     */
    $this->withHeader('X-Negocio', (string) $ajeno['negocios']['La Ajena'])
        ->getJson('/v1/negocio/me')
        ->assertStatus(403)
        ->assertJsonPath('message', 'Ese negocio no es tuyo.');
});

test('los pedidos que llegan son los del negocio activo y de ningun otro', function () {
    $mio   = tenderoConNegocios(['La Mía']);
    $ajeno = tenderoConNegocios(['La Ajena']);

    pedidoConfirmadoEn($mio['negocios']['La Mía']);
    pedidoConfirmadoEn($ajeno['negocios']['La Ajena']);
    pedidoConfirmadoEn($ajeno['negocios']['La Ajena']);

    Sanctum::actingAs($mio['user']);

    $r = $this->getJson('/v1/negocio/pedidos')->assertOk();

    expect($r->json('orders'))->toHaveCount(1);
});

test('un business_id en el cuerpo no cambia de quien son los pedidos', function () {
    $mio   = tenderoConNegocios(['La Mía']);
    $ajeno = tenderoConNegocios(['La Ajena']);

    pedidoConfirmadoEn($ajeno['negocios']['La Ajena']);

    Sanctum::actingAs($mio['user']);

    /*
     * El corazón del middleware: `merge()` pisa lo que mande el cliente con el
     * identificador ya comprobado. Sin eso, esta petición devolvería los
     * pedidos de la otra tienda aunque la puerta hubiera dejado pasar bien.
     */
    $r = $this->getJson(
        '/v1/negocio/pedidos?business_id=' . $ajeno['negocios']['La Ajena'],
    )->assertOk();

    expect($r->json('orders'))->toHaveCount(0);
});

test('una cuenta sin negocios no entra, y se le dice que pedir', function () {
    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);

    Sanctum::actingAs(User::factory()->create(['rol' => 2]));

    $this->getJson('/v1/negocio/me')
        ->assertStatus(403)
        ->assertJsonPath('message', 'Tu cuenta no administra ningún negocio. Pídele al equipo que te asigne uno.');
});

test('el resumen cuenta los nuevos y deja fuera los pagos sin confirmar', function () {
    $t = tenderoConNegocios(['La Mía']);
    $id = $t['negocios']['La Mía'];

    pedidoConfirmadoEn($id, 1);
    pedidoConfirmadoEn($id, 1);
    pedidoConfirmadoEn($id, 3);

    // Uno esperando que la pasarela confirme: no debe existir para la tienda,
    // ni para contarlo ni para ponerse a prepararlo.
    DB::table('orderssales')->insert([
        'buyer_id' => DB::table('buyer')->value('buyer_id'), 'busines_id' => $id,
        'subtotal' => 5000, 'domicilio' => 2000, 'total' => 7000,
        'sale_date' => now(), 'state' => 1, 'payment_state' => OrdersSales::ESPERANDO_PAGO,
    ]);

    Sanctum::actingAs($t['user']);

    $r = $this->getJson('/v1/negocio/me')->assertOk();

    expect($r->json('stats.nuevos'))->toBe(2)
        ->and($r->json('stats.en_curso'))->toBe(1);
});

/* ------------------------------ CATÁLOGO ------------------------------ */

function productoEn(int $businessId, string $nombre, float $precio = 1000): int
{
    $categoria = DB::table('category')->insertGetId(
        ['name' => 'General', 'state' => 1],
        'category_id',
    );

    $p = Product::create([
        'name' => $nombre, 'description' => '', 'category_id' => $categoria, 'state' => 1,
    ]);

    ProductBusiness::create([
        'busines_id' => $businessId, 'products_id' => $p->products_id,
        'price' => $precio, 'amount' => 10, 'qualification' => 0,
    ]);

    return $p->products_id;
}

test('el catalogo solo trae lo del negocio activo', function () {
    $mio   = tenderoConNegocios(['La Mía']);
    $ajeno = tenderoConNegocios(['La Ajena']);

    productoEn($mio['negocios']['La Mía'], 'Pan');
    productoEn($ajeno['negocios']['La Ajena'], 'Leche');

    Sanctum::actingAs($mio['user']);

    $r = $this->getJson('/v1/negocio/productos')->assertOk();

    expect($r->json('data'))->toHaveCount(1)
        ->and($r->json('data.0.name'))->toBe('Pan')
        ->and($r->json('data.0.compartido'))->toBe(0);
});

test('el precio de un producto exclusivo se cambia, y el nombre tambien', function () {
    $t = tenderoConNegocios(['La Mía']);
    $id = productoEn($t['negocios']['La Mía'], 'Pan', 1000);

    Sanctum::actingAs($t['user']);

    $this->putJson("/v1/negocio/productos/{$id}", ['price' => 2500, 'name' => 'Pan tajado'])
        ->assertOk();

    expect((float) ProductBusiness::where('products_id', $id)->value('price'))->toBe(2500.0)
        ->and(Product::find($id)->name)->toBe('Pan tajado');
});

test('el nombre de un producto que vende otra tienda no se toca, pero el precio si', function () {
    $mio   = tenderoConNegocios(['La Mía']);
    $ajeno = tenderoConNegocios(['La Ajena']);

    $id = productoEn($mio['negocios']['La Mía'], 'Arroz Diana 500g', 3000);

    // La misma fila de `products`, vendida también por el vecino.
    ProductBusiness::create([
        'busines_id' => $ajeno['negocios']['La Ajena'], 'products_id' => $id,
        'price' => 3200, 'amount' => 5, 'qualification' => 0,
    ]);

    Sanctum::actingAs($mio['user']);

    // Renombrarlo se la cambiaría al vecino sin que se entere.
    $this->putJson("/v1/negocio/productos/{$id}", ['name' => 'Arrocito'])
        ->assertStatus(422);

    expect(Product::find($id)->name)->toBe('Arroz Diana 500g');

    // El precio es de cada tienda: ese sí.
    $this->putJson("/v1/negocio/productos/{$id}", ['price' => 2800])->assertOk();

    expect((float) ProductBusiness::where('products_id', $id)
        ->where('busines_id', $mio['negocios']['La Mía'])->value('price'))->toBe(2800.0)
        ->and((float) ProductBusiness::where('products_id', $id)
            ->where('busines_id', $ajeno['negocios']['La Ajena'])->value('price'))->toBe(3200.0);
});

test('tocar un producto de otra tienda responde 404, no 403', function () {
    $mio   = tenderoConNegocios(['La Mía']);
    $ajeno = tenderoConNegocios(['La Ajena']);

    $id = productoEn($ajeno['negocios']['La Ajena'], 'Leche');

    Sanctum::actingAs($mio['user']);

    // Confirmar que existe pero es de otro ya sería decir algo de su catálogo.
    $this->putJson("/v1/negocio/productos/{$id}", ['price' => 1])
        ->assertStatus(404);
});

/* ------------------------------- FICHA -------------------------------- */

test('el tendero corrige su ficha pero no puede cambiar de duenos ni apagarse', function () {
    $t = tenderoConNegocios(['La Mía']);
    $id = $t['negocios']['La Mía'];

    Sanctum::actingAs($t['user']);

    $this->putJson('/v1/negocio/me', [
        'name'    => 'La Mía Express',
        'phone'   => '3001234567',
        'NIT'     => '900123456-1',
        // Los dos que `BusinessController@update` sí acepta y acá no existen.
        'state'     => 0,
        'owner_ids' => [999],
    ])->assertOk();

    $fila = DB::table('business')->where('busines_id', $id)->first();

    expect($fila->name)->toBe('La Mía Express')
        ->and($fila->NIT)->toBe('900123456-1')
        // Sigue encendido: apagarse lo saca del catálogo y esa decisión es de
        // la plataforma.
        ->and((int) $fila->state)->toBe(1)
        ->and(DB::table('owner_busines')->where('busines_id', $id)->count())->toBe(1);
});

/* ------------------------------ LA PUERTA ----------------------------- */

test('un dueno de conjunto no entra por la puerta del negocio', function () {
    foreach ([5 => 'dueno_conjunto', 2 => 'tendero'] as $id => $nombre) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $nombre, 'guard_name' => 'web']);
    }

    Sanctum::actingAs(User::factory()->create(['rol' => 5]));

    // Sin negocios atados, la puerta no lo deja: es la misma comprobación que
    // separa los dos edificios del panel de aliados.
    $this->getJson('/v1/negocio/me')->assertStatus(403);
});

test('cambiar el nombre de un producto propio queda auditado', function () {
    /*
     * El paquete de auditoría trae `audit.console => false`, y las pruebas
     * corren en consola: sin esto no se registraría nada y la prueba pasaría
     * o fallaría por el motivo equivocado.
     */
    config(['audit.console' => true]);

    $t = tenderoConNegocios(['La Mía']);
    $id = productoEn($t['negocios']['La Mía'], 'Pan', 1000);

    Sanctum::actingAs($t['user']);

    $this->putJson("/v1/negocio/productos/{$id}", ['name' => 'Pan tajado'])
        ->assertOk();

    /*
     * `Product` extiende `Audit`, que se engancha a los eventos del modelo.
     * Con `Product::where()->update()` la fila cambiaba y no se registraba
     * nada: un nombre pisado no se recupera de ninguna parte, así que el
     * rastro es lo único que queda.
     */
    $rastro = DB::table('audits')
        ->where('auditable_type', \App\Models\Product\Product::class)
        ->where('auditable_id', $id)
        ->where('event', 'updated')
        ->first();

    expect($rastro)->not->toBeNull()
        ->and($rastro->old_values)->toContain('Pan')
        ->and($rastro->new_values)->toContain('Pan tajado');
});
