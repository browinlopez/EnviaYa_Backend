<?php

use App\Models\User;

test('confirm password screen can be rendered', function () {
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

    $response = $this->actingAs($user)->get('/confirm-password');

    $response->assertStatus(200);
});

test('password can be confirmed', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/confirm-password', [
        'password' => 'password',
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();
});

test('password is not confirmed with invalid password', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/confirm-password', [
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors();
});
