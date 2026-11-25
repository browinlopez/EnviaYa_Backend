<?php

/**
 * EL LÍMITE DE INTENTOS DE /login.
 *
 * Es la barrera contra la fuerza bruta: 10 por minuto desde una IP. Se volvió
 * configurable (`auth.intentos_de_acceso`) solo para que el recorrido con
 * navegador del panel —que entra con una cuenta por área, seguidas— no se tope
 * con el 429. Lo que se cuida acá es que, sin tocar nada, siga en 10.
 */
it('sin configurar nada, el límite de acceso sigue en 10 por minuto', function () {
    expect(config('auth.intentos_de_acceso'))->toBe(10);

    $intento = fn () => $this->postJson('/v1/login', [
        'email' => 'nadie@example.com',
        'password' => 'no-es-esta',
    ]);

    for ($i = 0; $i < 10; $i++) {
        expect($intento()->status())->not->toBe(429);
    }

    $intento()->assertStatus(429);
});
