<?php

/*
|--------------------------------------------------------------------------
| SEMILLAS (SEEDERS)
|--------------------------------------------------------------------------
|
| Los seeders de prueba y de demostración crean usuarios, y un usuario tiene
| clave. Estos dos valores gobiernan eso.
|
| Van en un archivo de configuración y no se leen con `env()` desde el seeder a
| propósito: con `php artisan config:cache` —que es lo normal en producción—
| `env()` fuera de un archivo de configuración devuelve null. Un seeder que
| leyera así habría ignorado en silencio el permiso explícito justo en el único
| entorno donde importa.
|
*/

return [

    /*
     * Clave de los usuarios que crea `UsersSeeder`.
     *
     * Vacía es lo correcto en cualquier servidor de verdad: el seeder genera una
     * al azar y la imprime una vez. Se pone solo en desarrollo, donde conviene
     * que las pruebas de navegador entren con una clave conocida.
     */
    'clave' => env('SEED_PASSWORD'),

    /*
     * Permiso explícito para sembrar en producción.
     *
     * `UsersSeeder` y `DemoSeeder` se niegan a correr con APP_ENV=production,
     * porque crean usuarios falsos con clave conocida y pedidos que ensucian la
     * contabilidad. `db:seed --force` dentro de un despliegue lo habría hecho sin
     * que nadie se enterara.
     */
    'permitir_en_produccion' => (bool) env('SEED_ALLOW_PRODUCTION', false),

];
