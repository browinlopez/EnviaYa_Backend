<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

test('profile page is displayed', function () {
    /*
     * SIN VITE: estas pruebas piden una vista de Breeze, y sus layouts
     * usan `@vite(...)`, que lanza si falta `public/build/manifest.json`.
     * Ese fichero esta en `.gitignore` y el CI no construye assets, asi
     * que en el runner la pagina daba 500 mientras aqui daba 200 —por una
     * carpeta de septiembre de 2025 que nadie versiona—.
     *
     * Lo que esto comprueba es que la pagina RESPONDA: que la ruta exista,
     * que el middleware deje pasar y que la vista compile. No que haya
     * hojas de estilo.
     */
    $this->withoutVite();

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertNotNull($user->refresh()->email_verified_at);
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete('/profile', [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    $this->assertNull($user->fresh());
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->delete('/profile', [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrorsIn('userDeletion', 'password')
        ->assertRedirect('/profile');

    $this->assertNotNull($user->fresh());
});

/* ===================================================================== */
/*  BORRAR LA CUENTA NO DEJA FICHAS SUELTAS */
/* ===================================================================== */

test('borrar la cuenta se lleva su ficha de comprador si no tiene historial', function () {
    $user = User::factory()->create();

    $buyerId = DB::table('buyer')->insertGetId([
        'user_id' => $user->user_id, 'qualification' => 0,
        'belongs_to_complex' => 0, 'state' => 1,
    ]);

    $this->actingAs($user)->delete('/profile', ['password' => 'password']);

    /*
     * `buyer.user_id` es SET NULL —al contrario que `owner` y `domiciliary`,
     * que cascadean—, así que la ficha se quedaba con `user_id = NULL` y sin
     * forma de llegar a ella desde ningún sitio. En la base local quedaron
     * cinco así antes de que esto existiera.
     */
    expect(DB::table('buyer')->where('buyer_id', $buyerId)->exists())->toBeFalse();
});

test('pero si tiene pedidos la ficha se queda, para que el pedido no pierda a su comprador', function () {
    $user = User::factory()->create();

    $buyerId = DB::table('buyer')->insertGetId([
        'user_id' => $user->user_id, 'qualification' => 0,
        'belongs_to_complex' => 0, 'state' => 1,
    ]);

    $negocio = DB::table('business')->insertGetId([
        'name' => 'Tienda', 'state' => 1, 'qualification' => 0,
    ]);
    DB::table('orderssales')->insert([
        'buyer_id' => $buyerId, 'busines_id' => $negocio,
        'total' => 10000, 'state' => 4,
    ]);

    $this->actingAs($user)->delete('/profile', ['password' => 'password']);

    /*
     * Se conserva vacía de identidad: es lo que permite que un comprobante
     * emitido siga diciendo a qué comprador fue, aunque la persona ya no esté.
     * Borrarla dejaría el pedido con `buyer_id = NULL`.
     */
    $ficha = DB::table('buyer')->where('buyer_id', $buyerId)->first();

    expect($ficha)->not->toBeNull();
    expect($ficha->user_id)->toBeNull();
});
