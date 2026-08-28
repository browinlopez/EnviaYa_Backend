<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            /*
             * Dónde está `mysqldump`, que es lo que usa la copia de seguridad.
             *
             * En Linux suele estar en el PATH y no hace falta; en Windows casi
             * nunca lo está, y el error que da —"no se reconoce como un
             * comando"— no menciona la copia de seguridad en ningún momento.
             * Se deja configurable para que el mismo repositorio sirva en las
             * dos partes.
             */
            'dump' => array_filter([
                'dump_binary_path' => env('DB_DUMP_BINARY_PATH'),
                // Sin bloquear las tablas: en producción, un dump con LOCK
                // TABLES deja la aplicación esperando mientras copia.
                'use_single_transaction' => true,
                'timeout' => 60 * 10,
            ]),

            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,

            /*
            |------------------------------------------------------------------
            | LA HORA DE LA CONEXIÓN
            |------------------------------------------------------------------
            |
            | Sin esto la sesión usa la zona del SERVIDOR (`SYSTEM`), mientras
            | PHP trabaja en `America/Bogota`. Mientras las dos coincidan no se
            | nota; en cuanto no coinciden —un VPS Linux está en UTC salvo que
            | alguien lo cambie— aparecen dos relojes en la misma columna:
            |
            |   · lo que escribe Eloquent va en hora de Bogotá;
            |   · lo que rellena la base con `DEFAULT CURRENT_TIMESTAMP` va en
            |     hora del servidor.
            |
            | Dos filas creadas en el mismo instante quedarían con cinco horas
            | de diferencia, y `NOW()` en una consulta tampoco diría lo mismo
            | que `now()` en PHP.
            |
            | NO HACE FALTA PARA QUE LAS FECHAS SEAN CORRECTAS. Las columnas
            | `created_at` / `updated_at` las escribe SIEMPRE la aplicación, con
            | el reloj de PHP; no hay `DEFAULT CURRENT_TIMESTAMP` en ninguna
            | precisamente para no depender del servidor. Esto es para lo demás:
            | un `NOW()` en una consulta cruda, un `CURDATE()`, una comparación
            | de fechas hecha por la base.
            |
            | VIENE VACÍO A PROPÓSITO, y esto importa antes de ponerlo:
            |
            | MySQL guarda los `timestamp` en UTC y los convierte a la zona de
            | la sesión al leerlos. Si hasta ahora la sesión era UTC y se pasa a
            | `-05:00`, TODO lo ya guardado se lee cinco horas corrido. En una
            | base donde el servidor ya está en hora de Colombia no cambia nada;
            | en una donde está en UTC, mueve el pasado.
            |
            | Así que primero se mira, y después se pone:
            |
            |     php artisan db:zona-horaria
            |
            | Ese comando dice si las dos horas coinciden y si es seguro. El
            | valor es `-05:00` y no `America/Bogota`: el offset fijo no exige
            | que el servidor tenga cargadas las tablas de zonas horarias, y
            | Colombia no cambia de hora en verano.
            */
            'timezone' => env('DB_TIMEZONE'),

            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
