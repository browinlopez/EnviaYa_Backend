<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * La calle que escribe la persona.
 *
 * El formulario pide "Calle" —"Ej. Carrera 10 #20-30"—, la app la mandaba como
 * `street`, y ni la tabla tenía la columna ni el controlador la miraba: se
 * perdía en silencio. Lo único que quedaba era la dirección que devuelve el
 * mapa a partir del pin.
 *
 * Y esa no basta para entregar. El geocodificador da una aproximación; el
 * número de casa lo pone la persona, y es lo que el domiciliario necesita para
 * llegar a la puerta.
 */
function compradorConSesion(): User
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $user = User::factory()->create(['rol' => 1]);
    DB::table('buyer')->insert(['user_id' => $user->user_id, 'state' => 1]);
    Sanctum::actingAs($user);

    return $user;
}

test('la calle escrita se guarda', function () {
    $user = compradorConSesion();

    test()->postJson('/v1/users/addresses/add', [
        'user_id' => $user->user_id,
        'address' => 'Calle 84, Barranquilla',
        'street'  => 'Carrera 51B #82-40 apto 501',
    ])->assertCreated();

    expect(DB::table('user_address')->where('user_id', $user->user_id)->value('street'))
        ->toBe('Carrera 51B #82-40 apto 501');
});

test('sin calle escrita la dirección sigue guardándose', function () {
    // Es opcional: la del mapa por sí sola ya sirve para pedir.
    $user = compradorConSesion();

    test()->postJson('/v1/users/addresses/add', [
        'user_id' => $user->user_id,
        'address' => 'Calle 84, Barranquilla',
    ])->assertCreated();

    expect(DB::table('user_address')->where('user_id', $user->user_id)->exists())->toBeTrue();
});

test('al domiciliario le llegan las dos juntas', function () {
    /*
     * La del mapa sitúa la cuadra y la escrita lleva el número. Con una sola
     * el domiciliario se queda en la acera.
     */
    $user = compradorConSesion();

    $addressId = DB::table('user_address')->insertGetId([
        'user_id' => $user->user_id,
        'address' => 'Calle 84, Barranquilla',
        'street'  => 'Carrera 51B #82-40 apto 501',
        'state'   => 1,
    ], 'address_id');

    $buyerId = DB::table('buyer')->where('user_id', $user->user_id)->value('buyer_id');
    $businessId = DB::table('business')->insertGetId([
        'name' => 'Tienda', 'qualification' => 0, 'state' => 1,
    ]);

    DB::table('orderssales')->insert([
        'buyer_id' => $buyerId, 'busines_id' => $businessId, 'address_id' => $addressId,
        'total' => 4500, 'subtotal' => 2500, 'domicilio' => 2000,
        'sale_date' => now(), 'state' => 1, 'payment_state' => 'pending_cash',
    ]);

    // La ruta es POST con el user_id en el cuerpo, no GET con query.
    $r = test()->postJson('/v1/orders/user', ['user_id' => $user->user_id])->assertOk();

    expect($r->json('orders.0.delivery_address.address'))
        ->toBe('Carrera 51B #82-40 apto 501, Calle 84, Barranquilla');
});
