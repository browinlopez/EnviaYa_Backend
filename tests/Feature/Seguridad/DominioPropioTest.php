<?php

/**
 * Los enlaces que salen del servidor van siempre al dominio vigente.
 *
 * Falló en producción: el entorno siguió con `APP_URL` en el dominio anterior
 * después de la mudanza, y el enlace del correo de verificación mandaba a un
 * sitio que ya no existe. Nadie lo notó al desplegar; lo notó la primera
 * persona que intentó verificar su correo.
 */

use App\Support\DominioPropio;

/** Carga un archivo de configuración como lo haría Laravel con ese entorno. */
function configuracionCon(string $archivo, array $entorno): array
{
    foreach ($entorno as $k => $v) {
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
        putenv("{$k}={$v}");
    }

    $config = require base_path("config/{$archivo}.php");

    foreach (array_keys($entorno) as $k) {
        unset($_ENV[$k], $_SERVER[$k]);
        putenv($k);
    }

    return $config;
}

test('una dirección del dominio anterior se traduce con su misma ruta', function () {
    $viejo = DominioPropio::ANTERIOR;

    expect(DominioPropio::url("https://api.{$viejo}", 'x'))->toBe('https://api.vecipaya.com')
        ->and(DominioPropio::url("https://api.{$viejo}/verify-email?token=abc", 'x'))
        ->toBe('https://api.vecipaya.com/verify-email?token=abc')
        ->and(DominioPropio::url("https://{$viejo}", 'x'))->toBe('https://vecipaya.com')
        ->and(DominioPropio::url("https://www.{$viejo}/cobertura", 'x'))->toBe('https://www.vecipaya.com/cobertura')
        ->and(DominioPropio::url("https://aliados.{$viejo}", 'x'))->toBe('https://aliados.vecipaya.com');
});

test('el panel cambió de subdominio en la mudanza', function () {
    expect(DominioPropio::url('https://panel.' . DominioPropio::ANTERIOR, 'x'))
        ->toBe('https://admin.vecipaya.com');
});

test('cualquier otro dominio pasa sin tocarse', function () {
    foreach ([
        'http://127.0.0.1:8000',
        'https://api.vecipaya.com',
        'https://staging.example.com',
        // Se parece, pero no es el dominio anterior.
        'https://mal' . DominioPropio::ANTERIOR,
    ] as $url) {
        expect(DominioPropio::url($url, 'x'))->toBe($url);
    }

    expect(DominioPropio::url('', 'https://api.vecipaya.com'))->toBe('https://api.vecipaya.com')
        ->and(DominioPropio::url(null, 'https://vecipaya.com'))->toBe('https://vecipaya.com');
});

test('con el entorno viejo, el correo de verificación apunta al API vigente', function () {
    $viejo = DominioPropio::ANTERIOR;

    $app = configuracionCon('app', [
        'APP_URL'   => "https://api.{$viejo}",
        'PANEL_URL' => "https://panel.{$viejo}",
    ]);
    $servicios = configuracionCon('services', ['SITIO_URL' => "https://{$viejo}"]);

    expect($app['url'])->toBe('https://api.vecipaya.com')
        ->and($app['panel_url'])->toBe('https://admin.vecipaya.com')
        ->and($servicios['sitio']['url'])->toBe('https://vecipaya.com');
});
