<?php

/**
 * ¿Carga de verdad cada clase de `app/`?
 *
 * EL CI COMPRUEBA LA SINTAXIS CON `php -l`, Y ESO NO BASTA. Un `implements` a
 * una interfaz que no está importada pasa el lint —la sintaxis es correcta— y
 * revienta al cargar la clase, en producción, la primera vez que alguien usa
 * ese camino.
 *
 * Pasó el 10 de noviembre de 2026 con los siete eventos que difunden: se les
 * quitó el `use` y se les dejó el `implements`. `php -l` dio verde en los
 * siete y ninguno se podía instanciar.
 *
 * Se ejecuta sin levantar Laravel: basta el autoload de Composer.
 *
 *     php scripts/check_classes_load.php
 */
require __DIR__.'/../vendor/autoload.php';

$raiz = __DIR__.'/../app';
$malas = [];

$archivos = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS)
);

$miradas = 0;

foreach ($archivos as $archivo) {
    if ($archivo->getExtension() !== 'php') {
        continue;
    }

    $relativo = substr($archivo->getPathname(), strlen($raiz) + 1, -4);
    $clase = 'App'.DIRECTORY_SEPARATOR.$relativo;
    $clase = str_replace(['/', DIRECTORY_SEPARATOR], chr(92), $clase);

    try {
        // `class_exists` dispara el autoload, que es justo lo que se quiere
        // comprobar. Si la clase no es PSR-4 (helpers sueltos), devuelve
        // false sin error y no cuenta.
        if (class_exists($clase) || interface_exists($clase) || trait_exists($clase) || enum_exists($clase)) {
            $miradas++;
        }
    } catch (Throwable $e) {
        $malas[] = $clase.' — '.$e->getMessage();
    }
}

echo "clases cargadas: {$miradas}\n";

if ($malas !== []) {
    echo "\nNO CARGAN:\n";
    foreach ($malas as $m) {
        echo '  '.$m."\n";
    }

    exit(1);
}

echo "ninguna falla al cargar\n";
