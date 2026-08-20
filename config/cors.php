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

$origenes = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
)));

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

return [
    'paths' => ['api/*', 'v1/*', 'broadcasting/auth', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origenes,

    /*
     * Patrones, para casos como los despliegues de vista previa que generan
     * un subdominio distinto por rama. Se escriben como expresiones
     * regulares en `CORS_ALLOWED_ORIGIN_PATTERNS`, también separadas por
     * comas. Vacío por defecto: un patrón mal escrito abre más de lo que
     * pretende, así que se activa a conciencia y no por descuido.
     */
    'allowed_origins_patterns' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', '')),
    ))),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
