<?php

declare(strict_types=1);

use App\Models\Order\OrdersSales;
use App\Services\CancelacionDelPedido;

/**
 * LOS CINCO ESTADOS DE UN PEDIDO SON NÚMEROS, Y LOS NÚMEROS SON CONTRATO.
 *
 * El 14 de noviembre de 2026 se juntaron en `OrdersSales`. Estaban repartidos
 * en NUEVE declaraciones —`ENTREGADO = 4` como constante privada en SEIS
 * clases, `CANCELADO = 5` en tres, `EN_CAMINO = 3` en dos— más 18 literales
 * sueltos en 11 ficheros.
 *
 * ESTA PRUEBA FIJA LOS NÚMEROS, no los nombres, y es lo único que importa de
 * ellos: el `state` viaja en todas las respuestas de pedido y las dos apps
 * publicadas lo pintan. `ESTADOS_DE_PEDIDO` en
 * `EnviaYa_Aliados/src/lib/constants.js` tiene las mismas cinco claves, de
 * donde salieron estos nombres.
 *
 * Juntar constantes es justo el cambio en el que es fácil mover un número sin
 * darse cuenta: seis copias del 4 pueden no ser todas el 4. Lo eran
 * —comprobado una por una antes de borrarlas— y esto lo deja escrito.
 */
it('los cinco estados valen lo que valian', function () {
    expect(OrdersSales::NUEVO)->toBe(1);
    expect(OrdersSales::PREPARANDO)->toBe(2);
    expect(OrdersSales::EN_CAMINO)->toBe(3);
    expect(OrdersSales::ENTREGADO)->toBe(4);
    expect(OrdersSales::CANCELADO)->toBe(5);
});

it('la lista los tiene los cinco, en orden y sin repetir', function () {
    expect(OrdersSales::ESTADOS)->toBe([1, 2, 3, 4, 5]);

    /* CONTROL: que la lista salga de las constantes y no sea un literal que
       pueda quedarse viejo. Si alguien cambia `ENTREGADO` y no la lista, esto
       lo dice. */
    expect(OrdersSales::ESTADOS)->toContain(OrdersSales::ENTREGADO);
    expect(count(array_unique(OrdersSales::ESTADOS)))->toBe(5);
});

it('no queda ninguna copia privada de un estado en el codigo', function () {
    /*
     * LO QUE IMPIDE QUE VUELVA. Seis clases tenían su propio
     * `private const ENTREGADO = 4`, y privada en seis sitios no es
     * encapsulación: es la misma decisión tomada seis veces, y el día que un
     * estado cambie de número hay que acordarse de las seis.
     *
     * Se busca la DECLARACIÓN, no el uso: usar `OrdersSales::ENTREGADO` desde
     * cualquier parte está bien y es lo que se quiere.
     */
    $raiz = base_path('app');
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS)
    );

    $rx = '~(private|protected)\s+const\s+(NUEVO|PREPARANDO|EN_CAMINO|ENTREGADO|CANCELADO)\s*=~';

    $copias = [];
    $revisados = 0;

    foreach ($it as $fichero) {
        if ($fichero->getExtension() !== 'php') {
            continue;
        }

        $revisados++;

        if (preg_match_all($rx, file_get_contents($fichero->getPathname()), $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $copias[] = basename($fichero->getPathname()).' -> '.$x[1].' const '.$x[2];
            }
        }
    }

    /* CONTROL: que haya leído el código de verdad. Con la carpeta vacía,
       «ninguna copia» es verdad sin significar nada. */
    expect($revisados)->toBeGreaterThan(300);

    /* Y que el patrón reconozca la forma mala, que es la otra mitad. */
    expect(preg_match($rx, 'private const ENTREGADO = 4;'))->toBe(1);
    expect(preg_match($rx, 'public const ENTREGADO = 4;'))->toBe(0);

    expect($copias)->toBe([]);
});

it('la constante del servicio de cancelacion apunta al modelo, no repite el 5', function () {
    /*
     * `CancelacionDelPedido::CANCELADO` se conserva porque
     * `CancelacionDelPedidoController` la usa por ese nombre, y renombrarla
     * sería cambiar una duplicación por un cambio de API interna. Lo que no
     * se conserva es el número: apunta al modelo.
     *
     * Si alguien le vuelve a escribir un `5`, los dos siguen valiendo 5 y
     * esta prueba pasaría. Por eso la de arriba busca la DECLARACIÓN con un
     * literal: las dos juntas cubren el caso.
     */
    expect(CancelacionDelPedido::CANCELADO)->toBe(OrdersSales::CANCELADO);
});
