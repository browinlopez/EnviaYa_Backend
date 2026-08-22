<?php

/**
 * Qué orígenes pueden llamar a la API desde un navegador.
 *
 * Esto se prueba porque ya falló en producción y de la peor manera: el panel
 * se publicó en `admin.enviaya.com.co` mientras `CORS_ALLOWED_ORIGINS` decía
 * `https://panel.enviaya.com.co`. La API respondía 200 a todo —comprobado con
 * curl— pero devolvía siempre ese `Access-Control-Allow-Origin`, así que el
 * navegador bloqueaba cada petición del panel. No falla al desplegar ni al
 * arrancar: falla al intentar entrar, cuando ya está publicado.
 *
 * De ahí el patrón por defecto: cualquier subdominio del dominio propio.
 */

use Illuminate\Support\Facades\Config;

/** Simula la comprobación previa que hace el navegador antes de la petición. */
function preflight(string $origen): \Illuminate\Testing\TestResponse
{
    return test()->call('OPTIONS', '/v1/app/config', [], [], [], [
        'HTTP_ORIGIN'                         => $origen,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD'  => 'GET',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
    ]);
}

test('el patrón de fábrica cubre el dominio y todos sus subdominios', function () {
    foreach ([
        'https://enviaya.com.co',
        'https://www.enviaya.com.co',
        'https://admin.enviaya.com.co',
        'https://panel.enviaya.com.co',
        'https://api.enviaya.com.co',
        'https://ws.enviaya.com.co',
        // Uno que todavía no existe: el patrón tiene que cubrirlo igual, que
        // es justo el punto de no mantener una lista a mano.
        'https://tablero.enviaya.com.co',
    ] as $origen) {
        expect(preflight($origen)->headers->get('Access-Control-Allow-Origin'))
            ->toBe($origen, "debería autorizar {$origen}");
    }
});

test('deja fuera cualquier otro dominio', function () {
    foreach ([
        'https://sitio-malicioso.example',
        // El truco clásico: el dominio propio como prefijo de otro.
        'https://enviaya.com.co.malicioso.example',
        // Y como sufijo.
        'https://malicioso-enviaya.com.co',
    ] as $origen) {
        expect(preflight($origen)->headers->get('Access-Control-Allow-Origin'))
            ->not->toBe($origen, "NO debería autorizar {$origen}");
    }
});

test('no autoriza el mismo dominio sin cifrar', function () {
    // Con `supports_credentials`, aceptar http significaría que una sesión
    // viaje por un canal que cualquiera en la red puede leer.
    expect(preflight('http://enviaya.com.co')->headers->get('Access-Control-Allow-Origin'))
        ->not->toBe('http://enviaya.com.co');
});

test('el patrón sigue al dominio configurado en SITIO_URL', function () {
    // No está clavado a enviaya.com.co: si la plataforma cambia de dominio,
    // CORS lo sigue sin tocar código.
    Config::set('cors.allowed_origins_patterns', ['#^https://([a-z0-9-]+\.)*otrodominio\.com$#i']);

    expect(preflight('https://panel.otrodominio.com')->headers->get('Access-Control-Allow-Origin'))
        ->toBe('https://panel.otrodominio.com');
});

test('una lista explícita sigue mandando cuando se configura', function () {
    Config::set('cors.allowed_origins', ['https://solo-este.example']);
    Config::set('cors.allowed_origins_patterns', []);

    expect(preflight('https://solo-este.example')->headers->get('Access-Control-Allow-Origin'))
        ->toBe('https://solo-este.example');

    expect(preflight('https://admin.enviaya.com.co')->headers->get('Access-Control-Allow-Origin'))
        ->not->toBe('https://admin.enviaya.com.co');
});

test('cubre las rutas que los navegadores necesitan', function () {
    // `broadcasting/auth` es la que autoriza los canales privados de Reverb:
    // sin ella en la lista, el panel conecta el websocket y no recibe nada.
    expect(config('cors.paths'))
        ->toContain('v1/*')
        ->toContain('broadcasting/auth');
});

test('no se puede abrir con comodín', function () {
    // Con credenciales, el navegador rechaza `*`. Que nadie lo ponga creyendo
    // que simplifica: rompe todo en silencio.
    expect(config('cors.supports_credentials'))->toBeTrue()
        ->and(config('cors.allowed_origins'))->not->toContain('*');
});
