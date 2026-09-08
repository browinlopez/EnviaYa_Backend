<?php

use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
        // Sanctum::currentRequestHost(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    /*
     * CADUCIDAD DE LA SESION.
     *
     * Estaba en `null`, o sea que un token no caducaba NUNCA. Sumado a que la
     * app lo restaura al arrancar, un telefono desbloqueado daba acceso
     * completo y permanente: el saldo de efectivo de un domiciliario, la
     * direccion de cada cliente, la caja de un tendero.
     *
     * Ahora sale del entorno para poder encenderla sin tocar codigo, y sigue
     * apagada por defecto: ponerle caducidad obliga a volver a entrar a quien
     * no abra la app en ese plazo, y eso es una decision de negocio, no
     * tecnica.
     *
     * Para activarla, en el `.env` del servidor:
     *
     *     SANCTUM_EXPIRATION=43200      # 30 dias, en minutos
     *
     * Conviene hacerlo DESPUES de que el bloqueo con huella este en manos de
     * la gente: son las dos mitades del mismo problema, y con el bloqueo
     * puesto la caducidad molesta menos.
     */
    'expiration' => env('SANCTUM_EXPIRATION'),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];
