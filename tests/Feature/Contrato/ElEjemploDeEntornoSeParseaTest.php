<?php

use Dotenv\Dotenv;

/**
 * `.env.example` TIENE QUE PODERSE LEER.
 *
 * ESTA PRUEBA EXISTE POR UN FALLO REAL, y conviene decir cuál. El 16 de
 * noviembre de 2026 el CI de esta rama murió en «Preparar el entorno», a los
 * diecinueve segundos, antes de correr una sola prueba:
 *
 *     The environment file is invalid!
 *     Failed to parse dotenv file. Encountered an unexpected equals at [=======].
 *
 * Era un MARCADOR DE CONFLICTO que quedó suelto: al resolver una fusión se
 * quitaron el `<<<<<<<` y el `>>>>>>>` y se olvidó la línea de en medio. Se
 * coló en `1068e76` y nadie lo vio durante seis commits, porque en esta
 * máquina `.env` ya existe y nadie vuelve a copiar el ejemplo.
 *
 * LO QUE SE PIERDE SI ESTO NO SE VIGILA. El paso que falla es el PRIMERO del
 * trabajo, así que se lleva por delante la sintaxis, PHPStan, Pint, el
 * contrato OpenAPI y las 1410 pruebas: el informe dice «rojo» sin haber
 * medido nada. Y quien clone el repositorio por primera vez se encuentra con
 * que `php artisan key:generate` no arranca.
 *
 * Acá se comprueba leyéndolo DE VERDAD, con el mismo parseador que usa
 * Laravel, y no buscando marcadores con una expresión regular: un
 * `.env.example` se puede romper de más maneras que esa, y el parseador las
 * conoce todas.
 */
test('el fichero .env.example se parsea con el lector de Laravel', function () {
    $ruta = base_path();

    expect(file_exists($ruta.'/.env.example'))->toBeTrue();

    $variables = Dotenv::createArrayBacked($ruta, '.env.example')->load();

    /*
     * CONTROL: que haya salido algo. Si el parseador devolviera un array
     * vacío —un fichero truncado a cero, por ejemplo— no habría lanzado y
     * esta prueba pasaría estando ciega. El número va por debajo del de hoy
     * a propósito: añadir variables no tiene por qué ponerla roja.
     */
    expect(count($variables))->toBeGreaterThanOrEqual(80);
});
