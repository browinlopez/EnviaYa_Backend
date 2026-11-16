<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "reverb", "pusher", "ably", "redis", "log", "null"
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over WebSockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST', 'ws.vecipaya.com'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https', // esto fuerza TLS si el esquema es https
            ],
            'client_options' => [
                /*
                 * CUANTO SE ESPERA A REVERB ANTES DE RENDIRSE.
                 *
                 * Seis de los siete eventos usan `ShouldBroadcastNow`, o sea
                 * que la llamada HTTP a `ws.vecipaya.com` ocurre DENTRO de la
                 * peticion: al aceptar un pedido, al escribir en el chat, al
                 * usar un codigo de porteria. Guzzle no pone limite por
                 * defecto y el `try/catch` que envuelve cada `broadcast()`
                 * atrapa excepciones, NO lentitud: una conexion colgada no
                 * lanza nada, espera.
                 *
                 * Con Octane los trabajadores son un grupo fijo, asi que un
                 * Reverb lento los agota igual que lo haria una pasarela
                 * lenta. Tres segundos de sobra para un aviso que es una
                 * cortesia: si no llega, el cliente lo ve al abrir la app.
                 */
                'timeout' => (int) env('REVERB_TIMEOUT', 3),
                'connect_timeout' => (int) env('REVERB_CONNECT_TIMEOUT', 2),
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
