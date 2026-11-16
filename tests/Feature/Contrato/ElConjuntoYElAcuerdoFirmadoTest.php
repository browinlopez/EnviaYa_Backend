<?php

declare(strict_types=1);

use App\Http\Resources\Conjunto\ConjuntoResource;
use App\Http\Resources\Domiciliario\ContratoFirmadoResource;
use App\Http\Resources\Domiciliario\ContratoResource;

/**
 * LAS DOS ÚLTIMAS FORMAS QUE VIVÍAN DENTRO DE UN MÉTODO.
 *
 * Con estas dos, el medidor `scripts/formas_de_entidad.py` deja de encontrar
 * formas de entidad por migrar en `app/Http`: lo que queda son informes
 * —agregados, donde un Resource sería la ceremonia que §05 prohíbe— y dos
 * sitios mirados y decididos, anotados en la cabecera del propio medidor.
 *
 * LO QUE CADA UNA FIJA:
 *
 *  · el conjunto, que `require_authorization` llegue como booleano de verdad.
 *    Una cadena «0» es VERDADERA en JavaScript, así que el panel pintaría el
 *    interruptor de «exigir autorización» encendido en un conjunto que no la
 *    exige. Es el mismo molde que `Business::accepting_orders` lleva en sus
 *    `casts` por el mismo motivo, y el comentario de ese modelo cuenta que ya
 *    pasó.
 *
 *  · el acuerdo, que las dos formas del contrato NO se confundan: la que lee
 *    el domiciliario en su app antes de firmar, y la que ve el panel después.
 *    Dos momentos del mismo trámite.
 */
it('require_authorization llega como booleano, no como la cadena "0"', function () {
    $c = (object) [
        'complex_id' => 4,
        'name' => 'Conjunto Las Flores',
        'photo' => null,
        'address' => 'Carrera 7 #100-20',
        'phone' => '6017654321',
        'email' => 'admin@lasflores.co',
        'admin_name' => 'Marta',
        'nit' => '900777888',
        'gate_notes' => 'Después de las 10 p.m. no se reciben domicilios.',
        // Como llega del motor: una CADENA.
        'require_authorization' => '0',
        'towers_count' => 7,
        'apartments_per_tower' => 24,
        'latitude' => '4.6097100',
        'longitude' => '-74.0817500',
    ];

    $publicado = (new ConjuntoResource($c))->resolve();

    expect($publicado['require_authorization'])->toBeFalse();
    expect($publicado['require_authorization'])->not->toBe('0');

    /* Y las coordenadas como números, no como las cadenas del motor: el mapa
       del celador las usa para pintar el pin. */
    expect($publicado['latitude'])->toBeFloat();
});

it('las instrucciones de la porteria viajan en la ficha, no aparte', function () {
    $c = (object) [
        'complex_id' => 4, 'name' => 'X', 'photo' => null, 'address' => 'Y',
        'phone' => null, 'email' => null, 'admin_name' => null, 'nit' => null,
        'gate_notes' => 'La torre 7 no tiene ascensor.',
        'require_authorization' => 1, 'towers_count' => 7,
        'apartments_per_tower' => 24, 'latitude' => null, 'longitude' => null,
    ];

    /* Es la clave que justifica que esta forma sea UNA y no dos. El celador
       tiene que ver las instrucciones permanentes sin ir a buscarlas; si se
       las quitaran a la ficha «porque son del administrador», habría que
       pedirlas en otra llamada justo cuando alguien está esperando en la
       puerta. */
    expect((new ConjuntoResource($c))->resolve()['gate_notes'])
        ->toBe('La torre 7 no tiene ascensor.');
});

it('las dos formas del contrato son distintas: antes y despues de firmar', function () {
    $archivo = (object) [
        'id' => 12,
        'original_name' => 'acuerdo-beto.pdf',
        'size_bytes' => 48210,
        'object_key' => 'contratos/12.pdf',
    ];

    $firmado = (new ContratoFirmadoResource(
        $archivo,
        url: 'https://ejemplo/firmado.pdf',
        firmadoEl: '2026-11-01 09:00:00',
        ciudad: 'Bogotá',
    ))->resolve();

    $paraLeer = (new ContratoResource(
        (object) [
            'domiciliary_id' => 5,
            'contract_signed_at' => null,
            'contract_city' => null,
            'document' => null,
            'name' => 'Beto',
        ],
        null,
        [['tipo' => 'titulo', 'texto' => 'Acuerdo de colaboración']],
    ))->resolve();

    /* La del panel lleva el ARCHIVO; la de la app lleva el TEXTO y si le
       falta la cédula para poder firmar. Juntarlas obligaría a una de las dos
       pantallas a recibir lo que no usa. */
    expect(array_keys($firmado))
        ->toBe(['media_id', 'name', 'size', 'url', 'signed_at', 'city']);

    expect($paraLeer)->toHaveKey('texto');
    expect($paraLeer)->toHaveKey('falta_documento', true);
    expect($firmado)->not->toHaveKey('texto');
    expect($paraLeer)->not->toHaveKey('media_id');
});
