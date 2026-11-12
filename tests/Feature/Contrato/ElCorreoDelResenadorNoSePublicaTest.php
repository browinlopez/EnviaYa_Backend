<?php

declare(strict_types=1);

use App\Http\Resources\Resena\DomiciliarioDeLaResenaResource;
use App\Http\Resources\Resena\NegocioDeLaResenaResource;
use App\Http\Resources\Resena\ResenadorResource;
use App\Models\Business;
use App\Models\Buyer\Buyer;
use App\Models\Domiciliary;
use App\Models\User;

/**
 * EL CORREO DE QUIEN CALIFICA NO SE PUBLICA, Y AHORA SE DICE UNA SOLA VEZ.
 *
 * El bloque del reseñador estaba escrito DOS VECES —en
 * `ResenasDeNegocioController` y en `ResenasDeDomiciliarioController`—,
 * idéntico, y las dos copias llevaban anotado el mismo cuidado con sus
 * propias palabras:
 *
 *   «Endpoint público: no exponer el email del reseñador.»
 *   «Se omite el email: quién califica no tiene por qué quedar expuesto ante
 *    el domiciliario.»
 *
 * Eso es una decisión de privacidad tomada dos veces en dos sitios. Mientras
 * estuvieran las dos copias, añadir el correo en una dejaba la otra sin él y
 * nada lo notaba: no había prueba que comparara las dos listas, porque no
 * había una lista, había dos. Ahora hay una clase y esta prueba.
 *
 * LA TRAMPA QUE ESTE ARCHIVO TAMBIÉN FIJA, y es la parte que no se ve en un
 * diff: `DomiciliarioDeLaResenaResource` lleva un bloque `user` con los
 * MISMOS CUATRO NOMBRES DE CLAVE que `ResenadorResource` —`user_id`, `name`,
 * `qualification`, `state`— pero lee de otro sitio:
 *
 *   ResenadorResource                `user_id` y `name` de `user`;
 *                                    `qualification` y `state` de `buyer`
 *   DomiciliarioDeLaResenaResource   las cuatro de `user`
 *
 * Unirlos por parecerse cambiaría los valores de una de las dos respuestas
 * SIN cambiar una sola clave. La última prueba de este archivo lo mide con
 * valores distintos a los dos lados, para que la diferencia sea visible.
 */
function unCompradorQueResena(): Buyer
{
    $u = new User;
    $u->setRawAttributes([
        'user_id' => 9,
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'phone' => '3001234567',
        'address' => 'Calle 1',
        'qualification' => '3.00',
        'state' => 1,
    ], true);

    $b = new Buyer;
    $b->setRawAttributes([
        'buyer_id' => 4,
        'user_id' => 9,
        'qualification' => '4.50',
        'state' => 1,
    ], true);
    $b->setRelation('user', $u);

    return $b;
}

it('el bloque del resenador NO lleva el correo, ni el telefono, ni la direccion', function () {
    $publicado = (new ResenadorResource(unCompradorQueResena()))->resolve();

    foreach (['email', 'phone', 'address'] as $personal) {
        expect($publicado)->not->toHaveKey($personal);
    }

    /* Y que no se cuele por el valor: la lista pública de reseñas de una
       tienda es pública, así que lo que importa es que el correo no esté en
       ninguna parte del bloque, no solo que no haya una clave con ese
       nombre. */
    expect(json_encode($publicado))->not->toContain('ana@example.com');
});

it('CONTROL: el bloque SI lleva lo suyo, o no estaria comprobando nada', function () {
    $publicado = (new ResenadorResource(unCompradorQueResena()))->resolve();

    /* Sin esto, un Resource que devolviera un array vacío pasaría la prueba
       de arriba: «no sale el correo» es verdad cuando no sale nada. */
    expect(array_keys($publicado))->toBe(['user_id', 'name', 'qualification', 'state']);
    expect($publicado['name'])->toBe('Ana');
});

it('qualification y state salen de buyer, no de user', function () {
    /* El comprador tiene 4.50 y su persona 3.00, distintos a propósito. Si
       alguien «simplificara» leyendo las cuatro claves de `user` —que es lo
       que hace el bloque del domiciliario, con los mismos nombres— este
       valor cambiaría sin que cambiara ninguna clave. */
    $publicado = (new ResenadorResource(unCompradorQueResena()))->resolve();

    expect($publicado['qualification'])->toBe('4.50');
});

it('el negocio de la resena no es el de la ficha: la diferencia es city', function () {
    $b = new Business;
    $b->setRawAttributes([
        'busines_id' => 2,
        'name' => 'Tienda Ana',
        'phone' => '3009999999',
        'address' => 'Calle 2',
        'qualification' => '4.80',
        'razonSocial_DCD' => 'Tienda Ana SAS',
        'NIT' => '900123456',
        'logo' => 'logo.png',
        'city' => 'Bogotá',
        'state' => 1,
        'type' => 'tienda',
        'accepting_orders' => 1,
    ], true);

    $publicado = (new NegocioDeLaResenaResource($b))->resolve();

    /* Diez claves exactas. `city` entra —solo está en esta forma— y `type` y
       `accepting_orders` no —solo están en la ficha—. Medido así para que
       nadie una las dos formas por parecerse: les faltaría o les sobraría un
       campo a respuestas que ya leen apps publicadas. */
    expect(array_keys($publicado))->toBe([
        'business_id', 'name', 'phone', 'address', 'qualification',
        'razon_social', 'NIT', 'logo', 'city', 'state',
    ]);
    expect($publicado['city'])->toBe('Bogotá');
});

it('el user del domiciliario lee de user, que es la trampa de los nombres iguales', function () {
    $u = new User;
    $u->setRawAttributes([
        'user_id' => 11,
        'name' => 'Beto',
        'qualification' => '5.00',
        'state' => 1,
    ], true);

    $d = new Domiciliary;
    $d->setRawAttributes([
        'domiciliary_id' => 5,
        'user_id' => 11,
        'available' => 1,
        'qualification' => '2.00',
        'state' => 1,
    ], true);
    $d->setRelation('user', $u);

    $publicado = (new DomiciliarioDeLaResenaResource($d))->resolve();

    /* El domiciliario vale 2.00 y su persona 5.00. Las dos claves se llaman
       `qualification` y están a dos niveles distintos leyendo de dos objetos
       distintos: es exactamente el parecido que invita a juntar las clases. */
    expect($publicado['qualification'])->toBe('2.00');
    expect($publicado['user']['qualification'])->toBe('5.00');
    expect(array_keys($publicado['user']))->toBe(['user_id', 'name', 'qualification', 'state']);
});
