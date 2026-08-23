<?php

use App\Mail\VerifyEmailCustomMail;
use Illuminate\Support\Facades\Config;

/**
 * EL CORREO QUE RECIBE UN VECINO.
 *
 * Es el primer contacto de la plataforma con alguien, y durante meses llegó
 * con el logotipo de Laravel arriba y «© 2026 Laravel. All rights reserved.»
 * al pie. No era un descuido de la plantilla propia —esa sí dice VeciPa'Ya—
 * sino una regla de la de Laravel: si `config('app.name')` vale exactamente
 * «Laravel», su cabecera pinta el logo del framework. Y `APP_NAME` estaba en
 * `Laravel`.
 *
 * Se descubrió leyendo el correo tal cual sale, no el código: en desarrollo
 * `MAIL_MAILER=log` lo escribe entero en `laravel.log`.
 *
 * Estas pruebas miran el HTML ya compuesto. Un correo puede armarse sin
 * errores y aun así llevar la marca de otro.
 */

function correoDeVerificacion(): string
{
    return (new VerifyEmailCustomMail('https://ejemplo.test/verify?token=abc'))
        ->render();
}

test('el correo no lleva la marca de Laravel', function () {
    /*
     * Se fuerza el nombre problemático a propósito: es el valor con el que
     * estuvo en producción, y la plantilla no puede depender de que alguien se
     * acuerde de cambiarlo en cada despliegue.
     */
    Config::set('app.name', 'Laravel');

    $html = correoDeVerificacion();

    expect($html)->not->toContain('laravel.com/img')
        ->and($html)->not->toContain('Laravel Logo')
        ->and($html)->not->toContain('All rights reserved');
});

test('lleva el logotipo de VeciPaYa y su pie', function () {
    Config::set('services.sitio.url', 'https://ejemplo.test');

    $html = correoDeVerificacion();

    expect($html)->toContain('https://ejemplo.test/logotipo.png')
        ->and($html)->toContain('Todos los derechos reservados');
});

test('el logotipo sale una sola vez', function () {
    Config::set('services.sitio.url', 'https://ejemplo.test');

    /*
     * Estaba en la cabecera Y en el cuerpo: dos logotipos, uno encima del
     * otro. Al arreglar la cabecera había que quitar el del cuerpo, no
     * añadirle otro.
     */
    $veces = substr_count(correoDeVerificacion(), '/logotipo.png');

    expect($veces)->toBe(1);
});

test('el enlace de verificacion va en el correo', function () {
    $html = correoDeVerificacion();

    // Lo único que la persona tiene que poder hacer con este correo.
    expect($html)->toContain('https://ejemplo.test/verify?token=abc')
        ->and($html)->toContain('Verificar mi cuenta');
});
