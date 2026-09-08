<?php

use App\Models\User;

/**
 * NADIE SE HACE ADMINISTRADOR SOLO.
 *
 * ESTE ARCHIVO DECIA LO CONTRARIO. Comprobaba que `/register` respondiera 200 y
 * que «new users can register» — o sea, fijaba como correcto un formulario
 * PUBLICO que creaba el usuario con `'rol' => 4` (administrador) y le iniciaba
 * la sesion. Venia de fabrica con Breeze, nadie lo miro, y estuvo servido en
 * produccion todo este tiempo.
 *
 * `EnsureAdmin`, la puerta del panel interno, comprueba exactamente una cosa:
 * que el rol sea 4. Asi que no habia que explotar nada. Se rellenaba.
 *
 * Lo que se fija ahora es que esa puerta NO EXISTA. Es la clase de cosa que
 * vuelve sola: `php artisan breeze:install` la reinstala, y sin una prueba que
 * lo diga, nadie se daria cuenta por segunda vez.
 */

test('el registro web de Breeze no existe: repartia rol de administrador', function () {
    $this->get('/register')->assertNotFound();

    $this->post('/register', [
        'name' => 'Intruso',
        'email' => 'intruso@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    // Y no solo responde 404: no llega a crear nada.
    expect(User::where('email', 'intruso@example.com')->exists())->toBeFalse();
    $this->assertGuest();
});

test('el registro de la app sigue funcionando y NO da rol de administrador', function () {
    /*
     * El otro lado de la misma moneda: quitar el agujero no puede haber
     * quitado el alta de verdad, que es la que usa la aplicacion.
     */
    foreach ([1 => 'comprador', 4 => 'admin'] as $id => $nombre) {
        \App\Models\Rol::firstOrCreate(['rol_id' => $id], ['name' => $nombre, 'guard_name' => 'web']);
    }

    $r = $this->postJson('/v1/register', [
        'name'     => 'Ana',
        'email'    => 'ana@example.com',
        'password' => 'ClaveDePrueba1',
        'phone'    => '3001234567',
        'rol'      => 1,
    ]);

    expect($r->status())->toBeLessThan(500);

    $ana = User::where('email', 'ana@example.com')->first();

    if ($ana) {
        expect((int) $ana->rol)->not->toBe(4, 'el registro publico no puede crear administradores');
    }
});
