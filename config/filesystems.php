<?php

/*
|--------------------------------------------------------------------------
| TOPES DE TIEMPO CONTRA EL ALMACENAMIENTO DE OBJETOS
|--------------------------------------------------------------------------
|
| EL AGUJERO QUE ESTO CIERRA ES EL MISMO QUE TENIA BOLD.
|
| El SDK de AWS arranca en modo `legacy`, y en ese modo no pone ningun tope.
| Comprobado sobre la aplicacion en marcha y no leyendo documentacion: el
| cliente del disco `r2` se resolvia SIN una sola opcion de `http`, asi que
| valian los valores por defecto de Guzzle —`connect_timeout` y `timeout` en 0,
| o sea esperar indefinidamente—.
|
| Y las subidas a R2 ocurren DENTRO de la peticion: `MediaService` y
| `BusinessMediaService` escriben en el disco `r2` cuando el tendero sube el
| logo de su tienda o una foto de su negocio. Con Octane los trabajadores son
| un grupo fijo, asi que bastaba que R2 se QUEDARA COLGADO —ni siquiera que
| fallara— para agotarlos y tumbar el backend entero, no solo las imagenes. El
| `try/catch` que envuelve cada subida atrapa excepciones, NO lentitud: una
| conexion colgada no lanza nada, espera.
|
| EL DISCO DE LAS COPIAS LLEVA OTRO TOPE, Y MUCHO MAS LARGO. Un volcado de la
| base puede pesar cientos de megas. `S3Client::upload()` parte en trozos por
| encima de 16 MB y cada trozo es su propia peticion HTTP, asi que el tope se
| aplica POR TROZO y uno generoso no estorba. Un tope corto ahi no arreglaria
| nada: romperia la copia de seguridad para proteger de una espera que ocurre
| en un comando programado, de madrugada y con `withoutOverlapping` puesto.
|
| Las dos cosas salen del entorno porque el dia que R2 vaya lento de verdad hay
| que poder aflojar el tope sin desplegar.
*/
$topesDeMedios = [
    'connect_timeout' => (float) env('S3_CONNECT_TIMEOUT', 5),
    'timeout' => (float) env('S3_TIMEOUT', 30),
];

$topesDeRespaldos = [
    'connect_timeout' => (float) env('S3_BACKUP_CONNECT_TIMEOUT', 10),
    'timeout' => (float) env('S3_BACKUP_TIMEOUT', 900),
];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'http' => $topesDeMedios,
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
         * Cloudflare R2 — imágenes y documentos de los negocios.
         *
         * R2 habla el protocolo de S3, así que se usa el mismo driver con tres
         * particularidades:
         *  · `region` siempre es 'auto': R2 no tiene regiones al estilo AWS.
         *  · `use_path_style_endpoint` en true, porque el endpoint de R2 no
         *    admite el estilo virtual-host (bucket como subdominio).
         *  · `url` es el dominio PÚBLICO de lectura, que NO es el endpoint de
         *    la API: por r2.cloudflarestorage.com solo se escribe con firma.
         *
         * `throw` en true a propósito: si una subida falla se quiere el error,
         * no un `false` silencioso que deje al negocio sin imagen y sin aviso.
         */
        'r2' => [
            'driver' => 's3',
            'http' => $topesDeMedios,
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => 'auto',
            'bucket' => env('R2_BUCKET', 'vecipaya-media'),
            'endpoint' => env('R2_ENDPOINT'),
            'url' => env('R2_PUBLIC_URL'),
            'use_path_style_endpoint' => true,
            'throw' => true,
            'report' => false,
        ],

        /*
         * COPIAS DE SEGURIDAD: OTRO BUCKET, Y SIN URL PÚBLICA
         *
         * No se reutiliza el disco `r2` de arriba a propósito. Ese bucket tiene
         * dominio público de lectura —hace falta para que las imágenes se vean—
         * y spatie/laravel-backup guarda el volcado en una ruta predecible:
         * el nombre de la aplicación y la fecha. Con las dos cosas juntas, la
         * base de datos entera —personas, teléfonos, direcciones, pagos— queda
         * descargable por cualquiera que pruebe la URL.
         *
         * Por eso este disco va sin `url`: aunque alguien lo apunte por error a
         * un bucket público, `Storage::url()` no sabría construir el enlace.
         *
         * Si no se configura, `BACKUP_DISK` se queda en `local` y la copia vive
         * en el volumen del contenedor: protege de perder el contenedor, no de
         * perder el servidor.
         */
        'r2_respaldos' => [
            'driver' => 's3',
            // Generoso a proposito: ver la cabecera de este archivo.
            'http' => $topesDeRespaldos,
            'key' => env('R2_BACKUP_ACCESS_KEY_ID', env('R2_ACCESS_KEY_ID')),
            'secret' => env('R2_BACKUP_SECRET_ACCESS_KEY', env('R2_SECRET_ACCESS_KEY')),
            'region' => 'auto',
            'bucket' => env('R2_BACKUP_BUCKET', 'vecipaya-respaldos'),
            'endpoint' => env('R2_BACKUP_ENDPOINT', env('R2_ENDPOINT')),
            'use_path_style_endpoint' => true,
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
