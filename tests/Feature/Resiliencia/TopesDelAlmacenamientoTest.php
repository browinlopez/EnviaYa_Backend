<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

/**
 * LOS TOPES DE TIEMPO CONTRA R2
 *
 * El mismo agujero que tenía Bold, en otro sitio y sin encontrar hasta hoy.
 *
 * El SDK de AWS arranca en modo `legacy` y en ese modo no pone ningún tope, así
 * que valían los valores por defecto de Guzzle: `connect_timeout` y `timeout`
 * en 0, o sea esperar indefinidamente. Y las subidas a R2 ocurren DENTRO de la
 * petición —`MediaService` y `BusinessMediaService` escriben cuando el tendero
 * sube el logo de su tienda—, con Octane y su grupo fijo de trabajadores.
 *
 * MEDIDO, no deducido. Contra una dirección no enrutable, una subida tardaba
 * **63,6 segundos** sin topes y 3,2 con ellos. Y contra un socket que ACEPTA la
 * conexión y no contesta nunca —el caso que la red no salva— la llamada no
 * terminaba: se cortó a mano pasados dos minutos.
 *
 * ESE CONTRAEJEMPLO NO SE PUEDE ESCRIBIR COMO PRUEBA, y eso mismo es la medida
 * de la gravedad: una prueba que demostrara el fallo nunca acabaría. Así que lo
 * que se fija es el lado acotado —que el tope se respeta— y, sobre todo, que
 * los tres discos lo declaren.
 *
 * POR QUÉ SE COMPRUEBA LA CONFIGURACIÓN Y NO EL CLIENTE YA CONSTRUIDO: no hay
 * manera de leerlo de vuelta. `S3Client::getConfig()` no devuelve los
 * argumentos resueltos sino otra cosa, y las dos primeras versiones de esta
 * comprobación daban «no hay topes» con los topes puestos. Un detector que no
 * se puede verificar es peor que no tenerlo.
 */
function discosDeObjetos(): array
{
    return array_filter(
        config('filesystems.disks'),
        fn ($d) => ($d['driver'] ?? null) === 's3'
    );
}

it('los tres discos de objetos declaran sus topes, y ninguno en cero', function () {
    $discos = discosDeObjetos();

    // Si algún día se añade un disco de objetos, esta prueba lo alcanza sola:
    // recorre los que hay, no una lista escrita a mano.
    expect($discos)->not->toBeEmpty();

    /*
     * SE JUNTAN LOS CULPABLES EN VEZ DE CORTAR EN EL PRIMERO, para que el
     * fallo diga QUÉ disco y QUÉ le falta. Un `toHaveKey` dentro del bucle
     * para en el primero y obliga a correr la prueba tres veces para enterarse
     * de los tres.
     */
    $sinTope = [];

    foreach ($discos as $nombre => $config) {
        $topes = $config['http'] ?? null;

        if (! is_array($topes)) {
            $sinTope[] = $nombre.': no declara `http`';

            continue;
        }

        /*
         * MAYOR QUE CERO, no «presente». En Guzzle un cero significa ESPERAR
         * PARA SIEMPRE, así que un `'timeout' => 0` pasaría una comprobación de
         * existencia con el agujero abierto entero. Y un valor ausente es lo
         * mismo que un cero, porque ese es el valor por defecto.
         */
        foreach (['connect_timeout', 'timeout'] as $cual) {
            if ((float) ($topes[$cual] ?? 0) <= 0) {
                $sinTope[] = $nombre.': '.$cual.' en cero o ausente, o sea sin tope';
            }
        }
    }

    expect($sinTope)->toBe([]);
});

it('el disco de las copias aguanta mas que el de los medios', function () {
    /*
     * NO ES UN DETALLE DE ESTILO.
     *
     * Un volcado de la base pesa cientos de megas y se sube de madrugada desde
     * un comando programado. El de los medios atiende a alguien que está
     * mirando la pantalla con el teléfono en la mano. Unificar los dos números
     * —que es lo que haría cualquiera «limpiando» configuración repetida—
     * significa una de dos cosas, y las dos son malas: con el número corto se
     * rompe la copia de seguridad, y con el largo se devuelve al tendero la
     * espera de quince minutos que esto venía a quitar.
     */
    $medios = (float) config('filesystems.disks.r2.http.timeout');
    $respaldos = (float) config('filesystems.disks.r2_respaldos.http.timeout');

    expect($respaldos)->toBeGreaterThan($medios);
});

it('respeta el tope contra un servidor que acepta y no contesta nunca', function () {
    /*
     * EL CASO QUE LA RED NO SALVA.
     *
     * Contra una IP no enrutable es el sistema operativo el que acaba
     * rindiéndose —63 segundos, medidos—, así que un tope ahí solo acorta la
     * espera. Contra un servidor que COMPLETA el saludo TCP y luego se calla no
     * hay nada que la corte: el cliente espera la respuesta, y sin tope la
     * espera no acaba.
     *
     * Se monta con un socket local, así que no hace falta red y la prueba corre
     * igual en el CI. No se acepta ninguna conexión: el núcleo completa el
     * saludo y la deja en la cola, que es exactamente lo que hace un servidor
     * sobrecargado.
     */
    $servidor = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

    if ($servidor === false) {
        $this->markTestSkipped('No se pudo abrir un socket local: '.$error);
    }

    $puerto = (int) explode(':', stream_socket_get_name($servidor, false))[1];

    Config::set('filesystems.disks.sonda', [
        'driver' => 's3',
        'key' => 'sin-valor',
        'secret' => 'sin-valor',
        'region' => 'auto',
        'bucket' => 'sonda',
        'endpoint' => 'http://127.0.0.1:'.$puerto,
        'use_path_style_endpoint' => true,
        'throw' => false,
        'report' => false,
        // Sin reintentos: el SDK reintenta tres veces por su cuenta y la
        // prueba tardaría el triple sin demostrar nada más.
        'retries' => 0,
        'http' => ['connect_timeout' => 1, 'timeout' => 1],
    ]);

    Storage::forgetDisk('sonda');

    $empezo = microtime(true);

    try {
        Storage::disk('sonda')->put('sonda.txt', 'hola');
    } catch (Throwable $e) {
        // Que falle es lo esperado. Lo que se mide es CUÁNDO.
    }

    $tardo = microtime(true) - $empezo;

    fclose($servidor);

    /*
     * Ocho segundos y no «un poco más de uno»: el margen es para que la prueba
     * no se vuelva quisquillosa en una máquina cargada o en el CI. Lo que se
     * distingue es acotado de no acotado, y sin tope esto no termina: con el
     * valor de antes se cortó a mano pasados dos minutos.
     */
    expect($tardo)->toBeLessThan(8.0);
});
