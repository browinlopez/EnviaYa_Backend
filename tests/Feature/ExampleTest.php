<?php

/*
 * La raíz del API.
 *
 * Era la prueba de fábrica de Laravel: pedía `/` y esperaba 200, que es lo que
 * devolvía la landing vieja en Blade. Esa landing se retiró —el sitio público
 * vive en `EnviaYa_Landing`, aparte— y la raíz pasó a redirigir.
 *
 * Se conserva la prueba en vez de borrarla porque ahora sí comprueba algo: que
 * quien llega al dominio del API acabe en el sitio, y no en un error.
 */

it('la raíz redirige al sitio público', function () {
    $this->get('/')
        ->assertStatus(302)
        ->assertRedirect(config('services.sitio.url'));
});

it('el destino sale de la configuración, no escrito a mano', function () {
    // Cambiar de dominio no debería exigir tocar código.
    config(['services.sitio.url' => 'https://otro-sitio.example']);

    $this->get('/')->assertRedirect('https://otro-sitio.example');
});
