<?php

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;

return [
    /*
    |--------------------------------------------------------------------------
    | API Path
    |--------------------------------------------------------------------------
    | The path where your API lives. This is used to automatically guess which
    | of your routes are API routes. Scramble will document routes starting
    | with this path.
    */
    'api_path' => '/',

    /*
    |--------------------------------------------------------------------------
    | API Domain
    |--------------------------------------------------------------------------
    | The default is null, meaning it will use the app domain.
    */
    'api_domain' => null,

    /*
    |--------------------------------------------------------------------------
    | Document Routes
    |--------------------------------------------------------------------------
    | Scramble exposes two routes:
    |   - /docs/api       -> The Scalar UI
    |   - /docs/api.json  -> The raw OpenAPI JSON spec
    */
    'docs_path' => 'docs/api',
    'docs_middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Info Block
    |--------------------------------------------------------------------------
    | Configures the info block of the OpenAPI document.
    */
    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'title' => 'EnviaYa API',
        'description' => <<<MD
# EnviaYa Backend API

API REST para la plataforma de delivery EnviaYa. Esta documentación es **React Native First** y contiene ejemplos de código para Axios y Fetch en cada endpoint.

## Autenticación

La API usa **Laravel Sanctum** con tokens Bearer.
Todas las rutas protegidas requieren el header:

```
Authorization: Bearer {your_token}
Accept: application/json
```

Obtén tu token en `POST /auth/login`.

## Errores Estándar

| Código | Significado |
|--------|-------------|
| `401` | Token inválido o expirado |
| `403` | Sin permisos para este recurso |
| `404` | Recurso no encontrado |
| `422` | Error de validación (revisar campo `errors`) |
| `500` | Error interno del servidor |

## WebSockets / Reverb

Para eventos en tiempo real, usa `laravel-echo` con `pusher-js`.
Consulta la sección **Frontend Integration Contract** en `/docs/frontend-contract`.

## Arquitectura

- PostGIS para cálculos de distancia reales
- RabbitMQ para procesos asíncronos
- Laravel Reverb para WebSockets
MD,
    ],

    /*
    |--------------------------------------------------------------------------
    | Extensions
    |--------------------------------------------------------------------------
    | Extensions allow you to extend Scramble's behavior and add custom
    | annotations to the generated OpenAPI document.
    */
    'extensions' => [
        \App\Docs\ReactCodeSnippetsExtension::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Servers
    |--------------------------------------------------------------------------
    */
    'servers' => null,

    /*
    |--------------------------------------------------------------------------
    | Security Schemes
    |--------------------------------------------------------------------------
    */
    'security' => [],

    /*
    |--------------------------------------------------------------------------
    | UI
    |--------------------------------------------------------------------------
    | Sets the UI library for the docs. Options: 'stoplight-elements', 'scalar'.
    */
    'ui' => [
        'theme' => 'scalar',
    ],
];
