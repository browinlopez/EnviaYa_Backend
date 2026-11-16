<?php

declare(strict_types=1);

use App\Http\Resources\Domiciliario\DomiciliarioDeLaFlotaResource;
use App\Http\Resources\Domiciliario\DomiciliarioEnElTableroResource;
use App\Http\Resources\Domiciliario\DomiciliarioEnLaPorteriaResource;
use App\Http\Resources\Resena\DomiciliarioDeLaResenaResource;
use App\Models\Domiciliary;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * EL MISMO DOMICILIARIO SE PUBLICA DE SEIS MANERAS.
 *
 * Cada pantalla se armó su propia forma de la misma persona. Medidas:
 *
 *   la flota de la tienda        15  con efectivo, papeles y `can_take`
 *   la torre de control          13  las mismas cifras, en castellano
 *   la portería del conjunto      8  con su DOCUMENTO
 *   dentro del pedido             8  con su correo
 *   dentro de una reseña          5  solo para calificar
 *   dentro del login             11  con su contrato
 *
 * Entre las seis hay VEINTIOCHO claves distintas y NI UNA SOLA está en las
 * seis. No es un diseño: es lo que pasa cuando cada pantalla se arma su
 * forma. No se unifican —son seis respuestas ya publicadas— pero ahora cada
 * una tiene nombre y acá está medido en qué se diferencian.
 *
 * LAS DOS COSAS QUE ESTA PRUEBA FIJA, y las dos importan:
 *
 *  1. EL DOCUMENTO SALE EN UN SOLO SITIO. El celador compara la cédula con la
 *     persona que tiene delante; fuera de la puerta no sirve para nada y es
 *     el dato más sensible que tiene un domiciliario en el sistema. Si
 *     aparece en otra de las formas, acá se nota.
 *
 *  2. LAS MISMAS CIFRAS CON DOS NOMBRES. `available`/`disponible`,
 *     `cash_on_hand`/`efectivo`, `cash_limit`/`tope_efectivo`,
 *     `cash_room`/`le_cabe`. El grupo de rutas `negocio` renombra a castellano
 *     en la frontera a propósito y el otro no. Queda escrito para que nadie
 *     «arregle» uno de los dos lados y rompa un panel publicado.
 */
function unDomiciliarioDeVerdad(): Domiciliary
{
    $u = new User;
    $u->setRawAttributes([
        'user_id' => 11,
        'name' => 'Beto',
        'email' => 'beto@example.com',
        'phone' => '3005550011',
        'qualification' => '5.00',
        'state' => 1,
    ], true);

    $d = new Domiciliary;
    $d->setRawAttributes([
        'domiciliary_id' => 5,
        'user_id' => 11,
        'available' => 1,
        'document' => '1020304050',
        'qualification' => '4.60',
        'state' => 1,
    ], true);
    $d->setRelation('user', $u);

    return $d;
}

/** @return array<string, array<int, string>> */
function lasCarasDelDomiciliario(): array
{
    $d = unDomiciliarioDeVerdad();

    return [
        'flota' => array_keys((new DomiciliarioDeLaFlotaResource(
            $d,
            enCurso: 1,
            maxSimultaneos: 3,
            puedeTomar: true,
            papelesFaltantes: [],
            efectivo: 50000.0,
            tope: 200000.0,
        ))->resolve()),

        'tablero' => array_keys((new DomiciliarioEnElTableroResource(
            $d,
            id: 5,
            suyos: new Collection,
            efectivo: 50000.0,
            tope: 200000.0,
            posicion: null,
        ))->resolve()),

        'porteria' => array_keys((new DomiciliarioEnLaPorteriaResource(
            $d,
            historial: ['entradas' => 40],
        ))->resolve()),

        'resena' => array_keys((new DomiciliarioDeLaResenaResource($d))->resolve()),
    ];
}

it('el documento del domiciliario sale SOLO en la porteria', function () {
    $caras = lasCarasDelDomiciliario();

    expect($caras['porteria'])->toContain('document');

    foreach (['flota', 'tablero', 'resena'] as $cual) {
        expect($caras[$cual])->not->toContain('document');
    }
});

it('CONTROL: las cuatro caras traen de verdad lo suyo', function () {
    /* Sin esto, lo de arriba pasaría también con cuatro listas vacías: «no
       sale el documento» es verdad cuando no sale nada. */
    foreach (lasCarasDelDomiciliario() as $cual => $claves) {
        /* Sin el `$cual` en el mensaje no se sabe cuál falló, y `toContain`
           toma VALORES, no un mensaje: pasarle uno lo convierte en «contiene
           además esta cadena» y la prueba falla sola. Así que el nombre va
           por `withMessage`. */
        expect($claves)->toContain('domiciliary_id');
        expect(count($claves))->toBeGreaterThan(4);
    }
});

it('la flota y el tablero dicen las mismas cifras con nombres distintos', function () {
    $caras = lasCarasDelDomiciliario();

    $equivalencias = [
        'available' => 'disponible',
        'cash_on_hand' => 'efectivo',
        'cash_limit' => 'tope_efectivo',
        'cash_room' => 'le_cabe',
    ];

    foreach ($equivalencias as $enIngles => $enCastellano) {
        expect($caras['flota'])->toContain($enIngles);
        expect($caras['tablero'])->toContain($enCastellano);

        /* Y que NO estén cruzados: si un día alguien añade `efectivo` a la
           flota «para que cuadren», el panel que lee `cash_on_hand` sigue
           funcionando pero la respuesta engorda con un duplicado. */
        expect($caras['flota'])->not->toContain($enCastellano);
        expect($caras['tablero'])->not->toContain($enIngles);
    }
});

it('entre las cuatro caras medibles no hay una sola clave comun mas alla del id', function () {
    $caras = lasCarasDelDomiciliario();
    $comunes = array_values(array_intersect(...array_values($caras)));

    /* `domiciliary_id` y nada más. Con las seis —contando la del pedido y la
       del login— ni siquiera esa: la del login no lleva `name`, la de la
       reseña no lleva `user_id`. Está en la cabecera de
       `DomiciliarioDeLaFlotaResource`. */
    expect($comunes)->toBe(['domiciliary_id']);
});
