<?php

use App\Models\Marketing\Coupon;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Creación de pedido con cupón.
 *
 * Es la ruta más delicada que toca este módulo: `OrderController::store` ya
 * existía y movía dinero antes de que hubiera cupones. Lo que se prueba acá es
 * que el descuento se calcule en el servidor, que cuadre la aritmética del
 * pedido, que el uso se consuma una sola vez, y —sobre todo— que un pedido SIN
 * cupón siga comportándose exactamente igual que antes.
 */

/** Escenario mínimo para poder crear un pedido: comprador, negocio, producto. */
function escenarioPedido(): array
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $user = User::factory()->create(['rol' => 1]);
    Sanctum::actingAs($user);

    $buyerId = DB::table('buyer')->insertGetId([
        'user_id'            => $user->user_id,
        'qualification'      => 0,
        'belongs_to_complex' => 0,
        'state'              => 1,
    ]);

    $businessId = DB::table('business')->insertGetId([
        'name'          => 'Tienda del Pedido',
        'qualification' => 0,
        'state'         => 1,
    ]);

    $productId = DB::table('products')->insertGetId([
        'name'  => 'Arroz',
        'state' => 1,
    ], 'products_id');

    // El precio SIEMPRE sale de acá, nunca del cliente. La tabla pivote se
    // llama `products_business` (en plural), no `product_business`.
    DB::table('products_business')->insert([
        'busines_id'  => $businessId,
        'products_id' => $productId,
        'price'       => 10000,
        'amount'      => 100,
    ]);

    $addressId = DB::table('user_address')->insertGetId([
        'user_id' => $user->user_id,
        'address' => 'Carrera 1 # 2-3',
        'state'   => 1,
    ]);

    // Un método que NO sea 2 ni 5, para no entrar en el flujo de Bold.
    $methodId = DB::table('payment_methods')->insertGetId([
        'name'  => 'Efectivo',
        'state' => 1,
    ]);

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

/* ---------------------------------------------------------------------- */

test('un pedido sin cupón se comporta igual que antes', function () {
    // La red de seguridad del cambio: si esto se rompe, se rompió el checkout
    // de todo el mundo, no solo el de quien usa promociones.
    $e = escenarioPedido();

    $this->postJson('/v1/orders/orders', cuerpoPedido($e))
        ->assertSuccessful();

    $orden = DB::table('orderssales')->latest('orderSales_id')->first();

    expect((float) $orden->subtotal)->toBe(30000.0)   // 3 x 10.000
        ->and((float) $orden->discount)->toBe(0.0)
        ->and($orden->coupon_id)->toBeNull()
        ->and((float) $orden->total)->toBe(30000.0 + (float) $orden->domicilio);
});

test('un cupón porcentual descuenta y el total cuadra', function () {
    $e = escenarioPedido();

    Coupon::create([
        'code'      => 'MITAD',
        'type'      => 'percent',
        'value'     => 50,
        'starts_at' => now()->subDay()->toDateString(),
        'ends_at'   => now()->addDay()->toDateString(),
    ]);

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, ['coupon_code' => 'mitad']))
        ->assertSuccessful();

    $orden = DB::table('orderssales')->latest('orderSales_id')->first();

    expect((float) $orden->subtotal)->toBe(30000.0)
        ->and((float) $orden->discount)->toBe(15000.0)
        // El descuento sale del subtotal, NUNCA del domicilio: esa tarifa es
        // del domiciliario y descontarla saldría de su bolsillo.
        ->and((float) $orden->total)->toBe(30000.0 - 15000.0 + (float) $orden->domicilio);

    $canje = DB::table('coupon_redemptions')->first();
    expect($canje)->not->toBeNull()
        ->and((float) $canje->discount)->toBe(15000.0)
        ->and((int) $canje->order_id)->toBe((int) $orden->orderSales_id);

    expect((int) DB::table('coupons')->where('code', 'MITAD')->value('uses_count'))->toBe(1);
});

test('el descuento no se toma del cliente aunque lo mande', function () {
    $e = escenarioPedido();

    Coupon::create([
        'code'      => 'DIEZ',
        'type'      => 'percent',
        'value'     => 10,
        'starts_at' => now()->subDay()->toDateString(),
        'ends_at'   => now()->addDay()->toDateString(),
    ]);

    // Un cliente manipulado manda un descuento enorme: debe ignorarse.
    $this->postJson('/v1/orders/orders', cuerpoPedido($e, [
        'coupon_code' => 'DIEZ',
        'discount'    => 29999,
        'total'       => 1,
    ]))->assertSuccessful();

    $orden = DB::table('orderssales')->latest('orderSales_id')->first();

    expect((float) $orden->discount)->toBe(3000.0) // 10% de 30.000, recalculado
        ->and((float) $orden->total)->toBe(27000.0 + (float) $orden->domicilio);
});

test('rechaza el pedido con un cupón vencido y explica por qué', function () {
    $e = escenarioPedido();

    Coupon::create([
        'code'      => 'CADUCADO',
        'type'      => 'percent',
        'value'     => 30,
        'starts_at' => now()->subDays(10)->toDateString(),
        'ends_at'   => now()->subDays(2)->toDateString(),
    ]);

    $r = $this->postJson('/v1/orders/orders', cuerpoPedido($e, ['coupon_code' => 'CADUCADO']));

    $r->assertStatus(422);
    // El motivo tiene que llegar tal cual: si cayera en el catch genérico, el
    // usuario leería "Error al crear la orden" y reintentaría el mismo código.
    expect($r->json('message'))->toContain('no está vigente');

    // Y la transacción se deshizo entera: no queda media orden.
    expect(DB::table('orderssales')->count())->toBe(0);
});

test('respeta el tope de usos por persona', function () {
    $e = escenarioPedido();

    Coupon::create([
        'code'              => 'UNAVEZ',
        'type'              => 'fixed',
        'value'             => 2000,
        'max_uses_per_user' => 1,
        'starts_at'         => now()->subDay()->toDateString(),
        'ends_at'           => now()->addDay()->toDateString(),
    ]);

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, ['coupon_code' => 'UNAVEZ']))
        ->assertSuccessful();

    $segundo = $this->postJson('/v1/orders/orders', cuerpoPedido($e, ['coupon_code' => 'UNAVEZ']));

    $segundo->assertStatus(422);
    expect($segundo->json('message'))->toContain('número máximo de veces');

    // Solo el primero entró.
    expect(DB::table('orderssales')->count())->toBe(1)
        ->and(DB::table('coupon_redemptions')->count())->toBe(1);
});

test('respeta el pedido mínimo del cupón', function () {
    $e = escenarioPedido();

    Coupon::create([
        'code'      => 'GRANDE',
        'type'      => 'fixed',
        'value'     => 5000,
        'min_order' => 50000, // el pedido de prueba es de 30.000
        'starts_at' => now()->subDay()->toDateString(),
        'ends_at'   => now()->addDay()->toDateString(),
    ]);

    $r = $this->postJson('/v1/orders/orders', cuerpoPedido($e, ['coupon_code' => 'GRANDE']));

    $r->assertStatus(422);
    expect($r->json('message'))->toContain('aplica desde');
    expect(DB::table('orderssales')->count())->toBe(0);
});

test('un cupón atado a otro negocio no aplica', function () {
    $e = escenarioPedido();

    $otro = DB::table('business')->insertGetId([
        'name'          => 'Otro negocio',
        'qualification' => 0,
        'state'         => 1,
    ]);

    Coupon::create([
        'code'        => 'AJENO',
        'type'        => 'percent',
        'value'       => 20,
        'business_id' => $otro,
        'starts_at'   => now()->subDay()->toDateString(),
        'ends_at'     => now()->addDay()->toDateString(),
    ]);

    $r = $this->postJson('/v1/orders/orders', cuerpoPedido($e, ['coupon_code' => 'AJENO']));

    $r->assertStatus(422);
    expect($r->json('message'))->toContain('otro negocio');
});

test('un código que no existe no rompe el pedido en silencio', function () {
    $e = escenarioPedido();

    $r = $this->postJson('/v1/orders/orders', cuerpoPedido($e, ['coupon_code' => 'NOEXISTE']));

    // Se rechaza con explicación en vez de crear el pedido sin el descuento que
    // la pantalla anterior le prometió al usuario.
    $r->assertStatus(422);
    expect($r->json('message'))->toContain('no existe');
    expect(DB::table('orderssales')->count())->toBe(0);
});

test('un código vacío se ignora y el pedido pasa normal', function () {
    $e = escenarioPedido();

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, ['coupon_code' => '']))
        ->assertSuccessful();

    expect(DB::table('orderssales')->count())->toBe(1);
    expect((float) DB::table('orderssales')->value('discount'))->toBe(0.0);
});
