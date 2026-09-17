<?php

/**
 * QUIÉN PUEDE LLAMAR A LA API DESDE UN NAVEGADOR.
 *
 * Los tres frontales viven en subdominios distintos —`panel.` el del equipo,
 * `aliados.` el de tenderos y conjuntos, el dominio pelado la web— y para el
 * navegador cada uno es un origen aparte. Si uno se queda fuera de la lista,
 * su panel no falla al desplegar: falla al intentar entrar, cuando ya está
 * publicado y alguien lo está usando.
 *
 * Ya pasó una vez: producción tenía un único valor en `CORS_ALLOWED_ORIGINS`
 * y eso encendía un atajo de la librería —con exactamente un origen permitido
 * devuelve ESE origen sin mirar quién preguntó—, así que la web pública
 * recibía la cabecera del panel y el navegador bloqueaba todo.
 */

use Illuminate\Support\Facades\Config;

/** Recarga `config/cors.php` con el entorno que se le pase. */
function corsCon(array $entorno): array
{
    foreach ($entorno as $k => $v) {
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
        putenv("{$k}={$v}");
    }

    $config = require base_path('config/cors.php');

    foreach (array_keys($entorno) as $k) {
        unset($_ENV[$k], $_SERVER[$k]);
        putenv($k);
    }

    return $config;
}

test('el panel de aliados esta autorizado por su propia variable', function () {
    /*
     * Sin `ALIADOS_URL` entraba solo por el patrón comodín de subdominios, y
     * ese patrón desaparece en cuanto alguien define
     * `CORS_ALLOWED_ORIGIN_PATTERNS`. Nombrarlo aparte lo vuelve
     * independiente: es lo que evita que el panel de los tenderos se caiga por
     * un cambio hecho en otro sitio.
     */
    $cors = corsCon([
        'SITIO_URL'   => 'https://dominio-anterior.example',
        'PANEL_URL'   => 'https://panel.dominio-anterior.example',
        'ALIADOS_URL' => 'https://aliados.dominio-anterior.example',
        'APP_URL'     => 'https://api.dominio-anterior.example',
        'CORS_ALLOWED_ORIGIN_PATTERNS' => '#^https://nada\.example$#',
    ]);

    expect($cors['allowed_origins'])->toContain('https://aliados.dominio-anterior.example')
        ->and($cors['allowed_origins'])->toContain('https://panel.dominio-anterior.example')
        ->and($cors['allowed_origins'])->toContain('https://dominio-anterior.example');
});

test('nunca queda un solo origen permitido', function () {
    /*
     * Es la trampa de la librería: con exactamente uno y sin patrones,
     * responde ese origen a todo el mundo. La configuración suma siempre el
     * sitio, su `www`, los dos paneles y la propia API, así que la lista no
     * puede quedarse en uno ni queriendo.
     */
    $cors = corsCon([
        'CORS_ALLOWED_ORIGINS' => 'https://panel.dominio-anterior.example',
        'SITIO_URL'   => 'https://dominio-anterior.example',
        'PANEL_URL'   => 'https://panel.dominio-anterior.example',
        'ALIADOS_URL' => 'https://aliados.dominio-anterior.example',
        'APP_URL'     => 'https://api.dominio-anterior.example',
    ]);

    expect(count($cors['allowed_origins']))->toBeGreaterThan(1);
});

test('los puertos de desarrollo incluyen el de aliados', function () {
    // Sin nada configurado, el equipo tiene que poder trabajar con los tres
    // frontales: 5173/5174 el panel, 5175 la landing, 5176/5177 aliados.
    $cors = corsCon(['CORS_ALLOWED_ORIGINS' => '']);

    expect($cors['allowed_origins'])->toContain('http://localhost:5177')
        ->and($cors['allowed_origins'])->toContain('http://localhost:5174');
});

test('el resto de internet sigue fuera', function () {
    $cors = corsCon([
        'SITIO_URL'   => 'https://dominio-anterior.example',
        'ALIADOS_URL' => 'https://aliados.dominio-anterior.example',
    ]);

    /*
     * El comodín abre los subdominios PROPIOS, no cualquier cosa. Un dominio
     * ajeno —o uno generado por el proveedor de despliegue— no entra, y eso es
     * lo que hay que comprobar: `supports_credentials` está en `true`, así que
     * un origen autorizado puede llamar con la sesión de quien lo visite.
     */
    expect($cors['allowed_origins'])->not->toContain('https://vecipaya.com')
        ->and($cors['allowed_origins'])->not->toContain('*');

    $patron = $cors['allowed_origins_patterns'][0] ?? null;

    expect($patron)->not->toBeNull()
        ->and((bool) preg_match($patron, 'https://aliados.dominio-anterior.example'))->toBeTrue()
        ->and((bool) preg_match($patron, 'https://algo.dokploy.app'))->toBeFalse()
        // Sin HTTPS tampoco: la sesión viajaría en claro.
        ->and((bool) preg_match($patron, 'http://aliados.dominio-anterior.example'))->toBeFalse();
});

/**
 * Si algún patrón del conjunto deja pasar el origen.
 */
function algunPatronAcepta(array $cors, string $origen): bool
{
    foreach ($cors['allowed_origins_patterns'] as $patron) {
        if (preg_match($patron, $origen)) {
            return true;
        }
    }

    return false;
}

test('vecipaya.com entra aunque el entorno siga diciendo dominio-anterior.example', function () {
    /*
     * LA MUDANZA DE DOMINIO NO PUEDE DEPENDER DEL ORDEN.
     *
     * El 2026-09-14 la plataforma volvió a vecipaya.com —api., admin., aliados.
     * y ws.—, con producción todavía en SITIO_URL=https://dominio-anterior.example. Con
     * el comodín atado solo a esa variable, el panel desplegado en
     * admin.vecipaya.com habría recibido un CORS negado en cada petición.
     */
    $cors = corsCon(['SITIO_URL' => 'https://dominio-anterior.example']);

    foreach (['https://admin.vecipaya.com', 'https://aliados.vecipaya.com', 'https://vecipaya.com', 'https://www.vecipaya.com'] as $origen) {
        expect(algunPatronAcepta($cors, $origen))->toBeTrue("{$origen} debería entrar");
    }

    // Y el dominio viejo sigue valiendo mientras dure la transición.
    expect(algunPatronAcepta($cors, 'https://admin.dominio-anterior.example'))->toBeTrue();
});

test('el dominio fijo no abre nada que se le parezca', function () {
    /*
     * Un patrón mal anclado deja pasar `vecipaya.com.otro.com` o
     * `malvecipaya.com`, y con credenciales eso es la sesión del usuario en
     * manos de un tercero.
     */
    $cors = corsCon(['SITIO_URL' => 'https://dominio-anterior.example']);

    foreach ([
        'https://vecipaya.com.malicioso.example',
        'https://malvecipaya.com',
        'http://admin.vecipaya.com',
        'https://vecipaya.co',
    ] as $origen) {
        expect(algunPatronAcepta($cors, $origen))->toBeFalse("{$origen} NO debería entrar");
    }
});
