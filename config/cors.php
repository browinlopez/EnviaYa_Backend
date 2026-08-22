<?php

/*
|--------------------------------------------------------------------------
| ORÍGENES QUE PUEDEN LLAMAR A LA API DESDE UN NAVEGADOR
|--------------------------------------------------------------------------
|
| Hasta ahora la lista era una sola URL escrita a mano: `localhost:5173`.
| Funcionaba mientras el único cliente web era el panel en desarrollo, pero
| deja fuera a cualquier otra cosa —el panel en producción, la landing, o el
| mismo panel arrancado en otro puerto— y obliga a tocar código y desplegar
| para autorizar un dominio.
|
| Ahora sale de `CORS_ALLOWED_ORIGINS`, una lista separada por comas.
|
| NO SE PUEDE USAR '*'. Con `supports_credentials => true` el navegador
| rechaza el comodín: hay que nombrar cada origen. Es una molestia a
| propósito, porque la alternativa es que cualquier sitio pueda hacer
| peticiones con la sesión de quien lo visita.
|
| La app móvil no pasa por acá: CORS es cosa de navegadores.
|
*/

/**
 * Deja una URL en lo único que el navegador compara: esquema, host y puerto.
 * `https://enviaya.com.co/` y `https://enviaya.com.co/cobertura` son el mismo
 * origen, pero si la lista guarda la barra final o la ruta, la comparación es
 * literal y nunca coincide.
 */
$aOrigen = static function (?string $url): ?string {
    $url = trim((string) $url);

    if ($url === '') {
        return null;
    }

    $partes = parse_url($url);

    if (empty($partes['scheme']) || empty($partes['host'])) {
        return null;
    }

    return $partes['scheme'].'://'.$partes['host']
        .(isset($partes['port']) ? ':'.$partes['port'] : '');
};

$origenes = array_values(array_filter(array_map(
    $aOrigen,
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
)));

/*
| Los dominios que la aplicación YA tiene configurados se autorizan solos.
|
| Producción tenía un único valor en `CORS_ALLOWED_ORIGINS` —el panel— y eso
| encendía un atajo de la librería de CORS: cuando hay exactamente un origen
| permitido, devuelve ESE origen en la cabecera sin mirar quién preguntó. Así
| que la web pública recibía `Access-Control-Allow-Origin: panel...` y el
| navegador bloqueaba todas las llamadas de /cobertura.
|
| Autorizar de entrada la web pública, el panel y la propia API evita que la
| lista se quede corta y, de paso, que vuelva a quedar con un solo elemento.
| No hay dominios escritos acá: salen de SITIO_URL, PANEL_URL y APP_URL.
*/
$sitio = $aOrigen(env('SITIO_URL'));

$propios = [
    $sitio,
    // El `www` de la web pública es otro origen para el navegador, aunque el
    // DNS lo apunte al mismo sitio con un CNAME.
    $sitio !== null && ! str_contains($sitio, '://www.')
        ? str_replace('://', '://www.', $sitio)
        : null,
    $aOrigen(env('PANEL_URL')),
    $aOrigen(env('APP_URL')),
];

if ($origenes === []) {
    // Los puertos de desarrollo del monorepo: 5173 y 5174 son el panel
    // (Vite salta al siguiente si el primero está ocupado) y 5175 la
    // landing. Sin variable configurada, al menos el equipo puede trabajar.
    $origenes = [
        'http://localhost:5173',
        'http://localhost:5174',
        'http://localhost:5175',
        'http://127.0.0.1:5173',
        'http://127.0.0.1:5174',
        'http://127.0.0.1:5175',
    ];
}

$origenes = array_merge($origenes, array_filter($propios));

/*
 * PATRONES DE ORIGEN
 *
 * Por defecto, cualquier subdominio del dominio propio sobre HTTPS. Es lo que
 * evita la clase de error que ya costó un despliegue: el panel se publicó en
 * `admin.` mientras la lista fija decía `panel.`, así que la API respondía
 * siempre `Access-Control-Allow-Origin: https://panel.enviaya.com.co` y el
 * navegador bloqueaba TODAS las peticiones del panel. No falla al desplegar:
 * falla al intentar entrar.
 *
 * El dominio sale de `SITIO_URL`, así que sigue a la plataforma sin tocar
 * código. `CORS_ALLOWED_ORIGIN_PATTERNS` lo reemplaza si hace falta otra cosa.
 *
 * QUÉ ABRE ESTO, DICHO CLARO: con `supports_credentials => true`, cualquier
 * subdominio de enviaya.com.co puede llamar a la API con la sesión de quien
 * visite ese subdominio. Es aceptable porque son subdominios propios, pero
 * significa que un registro DNS apuntando a un tercero —un servicio de
 * terceros abandonado, por ejemplo— heredaría ese permiso. No es lo mismo que
 * `*`: el resto de internet sigue fuera.
 */
$patrones = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', '')),
)));

if ($patrones === []) {
    $anfitrion = parse_url((string) env('SITIO_URL', 'https://enviaya.com.co'), PHP_URL_HOST)
        ?: 'enviaya.com.co';

    // Sin comas: la lista de patrones se parte por comas, así que un
    // cuantificador como {2,3} rompería el patrón en dos mitades inválidas.
    $patrones = ['#^https://([a-z0-9-]+\.)*' . preg_quote($anfitrion, '#') . '$#i'];
}

return [
    'paths' => ['api/*', 'v1/*', 'broadcasting/auth', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique($origenes)),

    /*
     * Patrones, para casos como los despliegues de vista previa que generan
     * un subdominio distinto por rama, o para autorizar de una vez todos los
     * subdominios del dominio propio. Se escriben como expresiones regulares
     * CON delimitadores en `CORS_ALLOWED_ORIGIN_PATTERNS`, separadas por
     * comas (así que el patrón no puede llevar comas, ni cuantificadores
     * como `{2,3}`). Vacío por defecto: un patrón mal escrito abre más de lo
     * que pretende, así que se activa a conciencia y no por descuido.
     */
    'allowed_origins_patterns' => $patrones,

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
