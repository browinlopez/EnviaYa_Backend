<?php

declare(strict_types=1);

use App\Http\Resources\Negocio\NegocioDelListadoResource;
use App\Http\Resources\Negocio\NegocioFavoritoResource;
use App\Models\Business;
use Illuminate\Database\Eloquent\Collection as Eloquentes;

/**
 * LOS LISTADOS DE NEGOCIO, Y EN QUÉ SE DIFERENCIAN EXACTAMENTE.
 *
 * La app pinta el mismo negocio en varias pantallas, y cada una recibía una
 * forma distinta armada a mano dentro de un controlador:
 *
 *   index()                 el listado por cercanía          23 claves
 *   indexByQualification()  el de mejor calificadas, público 24 claves
 *   myFavorites()           la lista de favoritos            22 claves
 *
 * Las tres comparten diecinueve claves. Comparadas línea a línea con los
 * comentarios quitados, las dos primeras diferían en EXACTAMENTE DOS COSAS
 * —`description` y la clase del Resource de productos—, y nadie lo sabía
 * porque estaban a ciento setenta líneas una de otra.
 *
 * POR QUÉ ESTA PRUEBA NO ES CEREMONIA. Una divergencia así no da error: da
 * una pantalla a la que le falta un dato. Y es exactamente lo que había
 * pasado ya dos veces en esta familia, las dos escritas en el código:
 *
 *   · la cédula del tendero se dejó de publicar en los dos listados del
 *     catálogo y la de favoritos se quedó sin tocar, así que bastaba marcar
 *     la tienda como favorita para volver a leerla entera;
 *   · el precio de los productos de favoritos no comprueba la afiliación, al
 *     contrario que los del catálogo.
 *
 * Mientras las formas vivieran dentro de tres métodos, la única manera de
 * notar una diferencia era leer los tres y acordarse. Acá está medida: si
 * alguien añade un campo a una y no a las otras, esta prueba lo dice y hay
 * que decidirlo a propósito.
 *
 * LO QUE ESTA PRUEBA NO AFIRMA: que las diferencias estén bien. Dos de ellas
 * son asuntos de producto sin decidir, y están nombradas abajo con su
 * consecuencia para que la decisión se tome mirándolas.
 */
function unNegocioParaElListado(): Business
{
    $b = new Business;
    $b->setRawAttributes([
        'busines_id' => 3,
        'name' => 'Tienda Ana',
        'description' => 'Abarrotes y fruta',
        'phone' => '3009999999',
        'address' => 'Calle 2',
        'latitude' => '4.6097100',
        'longitude' => '-74.0817500',
        'qualification' => '4.80',
        'razonSocial_DCD' => 'Tienda Ana SAS',
        'NIT' => '900123456',
        'logo' => null,
        'state' => 1,
        'accepting_orders' => 1,
        'type' => 'tienda',
        'city' => 'Bogotá',
    ], true);

    $b->setRelation('municipality', null);
    $b->setRelation('owners', new Eloquentes);
    $b->setRelation('reviews', new Eloquentes);

    return $b;
}

/** @return array<int, string> */
function clavesDelListado(bool $conDescripcion): array
{
    return array_keys((new NegocioDelListadoResource(
        unNegocioParaElListado(),
        reparto: [
            'distance_km' => 1.2,
            'delivery_fee' => 2000,
            'delivery_time_minutes' => 18,
            'in_range' => true,
        ],
        productos: [],
        resenas: [],
        estaAfiliado: true,
        conDescripcion: $conDescripcion,
    ))->resolve());
}

/** @return array<int, string> */
function clavesDeFavoritos(): array
{
    return array_keys((new NegocioFavoritoResource(
        unNegocioParaElListado(),
        favoritoId: 7,
        reparto: [
            'distance_km' => 1.2,
            'delivery_fee' => 2000,
            'in_range' => true,
        ],
        productos: [],
        resenas: [],
    ))->resolve());
}

it('los dos listados del catalogo difieren en UNA sola clave: description', function () {
    $cercania = clavesDelListado(false);
    $calificacion = clavesDelListado(true);

    expect(array_values(array_diff($calificacion, $cercania)))->toBe(['description']);
    expect(array_values(array_diff($cercania, $calificacion)))->toBe([]);
});

it('description va entre address y qualification, no al final', function () {
    $claves = clavesDelListado(true);
    $donde = array_search('description', $claves, true);

    /* El orden importa porque la comparación de esta tanda fue byte a byte:
       una clave condicional puesta fuera del spread se iría al final y
       cambiaría el JSON sin cambiar su contenido. */
    expect($claves[$donde - 1])->toBe('address');
    expect($claves[$donde + 1])->toBe('qualification');
});

it('favoritos le falta lo que le falta, y esta dicho cual es la consecuencia', function () {
    $catalogo = clavesDelListado(true);
    $favoritos = clavesDeFavoritos();

    expect(array_values(array_diff($catalogo, $favoritos)))->toBe([
        'delivery_time_minutes',
        'accepting_orders',
        'is_affiliated',
    ]);
    expect(array_values(array_diff($favoritos, $catalogo)))->toBe(['favorite_id']);
});

it('desde favoritos la app no puede saber si la tienda esta en pausa', function () {
    /* La consecuencia de que falte `accepting_orders`, dicha aparte para que
       se lea como lo que es. En la ficha y en los listados del catálogo ese
       campo existe para poder decir «no está recibiendo pedidos ahora» y
       apagar el carrito, en vez de dejar pedir para fallar al final. Desde la
       lista de favoritos eso no se puede.

       Si algún día se añade, esta prueba se pone roja y hay que borrarla: será
       la señal de que la decisión se tomó. */
    expect(clavesDeFavoritos())->not->toContain('accepting_orders');
    expect(clavesDelListado(true))->toContain('accepting_orders');
});

it('las tres publican el propietario por el mismo presentador', function () {
    /* La fuga de la cédula del tendero se cerró en los dos listados del
       catálogo y favoritos se quedó sin tocar. Lo que se fija acá no es que
       `owners` exista, sino que las tres formas lo tengan: si alguien añade
       un cuarto listado sin él, o se lo quita a uno, se nota. */
    foreach ([clavesDelListado(false), clavesDelListado(true), clavesDeFavoritos()] as $claves) {
        expect($claves)->toContain('owners');
        expect($claves)->toContain('owner_count');
    }
});
