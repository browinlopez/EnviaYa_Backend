<?php

use App\Services\AgrupadorDeVariantes;

/**
 * El agrupador de variantes, que decide qué productos son el mismo con otra
 * presentación.
 *
 * Lo que se prueba acá no es que agrupe: es que NO agrupe de más. Un grupo que
 * falta se nota enseguida —el comprador ve dos tarjetas donde esperaba una— y
 * se arregla corriendo el comando otra vez. Un grupo de más esconde un
 * producto dentro de otro, nadie lo ve, y el cliente recibe algo que no pidió.
 *
 * Por eso casi todos los casos de abajo son de la forma «esto parece lo mismo
 * y no lo es».
 */

function agrupador(): AgrupadorDeVariantes
{
    return new AgrupadorDeVariantes();
}

/** Fila mínima como la que devuelve `products`. */
function producto(int $id, string $nombre, ?int $categoria = 1, ?string $marca = null, ?string $imagen = null): array
{
    return [
        'products_id' => $id,
        'name'        => $nombre,
        'barcode'     => null,
        'brand'       => $marca,
        'category_id' => $categoria,
        'image'       => $imagen,
    ];
}

/* ------------------------------------------------------------------ */
/* LEER UN NOMBRE                                                      */
/* ------------------------------------------------------------------ */

it('separa el nucleo de la presentacion', function (string $nombre, string $nucleo, ?string $etiqueta) {
    [$n, $e] = agrupador()->leer($nombre);

    expect($n)->toBe($nucleo);
    expect($e)->toBe($etiqueta);
})->with([
    // El caso corriente: mismo producto, distinto tamaño.
    ['SALSA DE TOMATE SAN JORGE  *170 GR', 'JORGE SALSA SAN TOMATE', '170 g'],
    ['SALSA TOMATE SAN JORGE  *1000 GR', 'JORGE SALSA SAN TOMATE', '1000 g'],

    // El sabor es parte de la etiqueta, no del núcleo.
    ['MR TEA DURAZNO 500ML', 'MR TEA', 'Durazno 500 ml'],
    ['MR TEA LIMON 1.5 LT', 'MR TEA', 'Limon 1,5 L'],

    // «1.5» sin unidad, al final y con decimales, son litros.
    ['COCA COLA 1.5', 'COCA COLA', '1,5 L'],

    // Un número pegado a una palabra de variante es parte de la etiqueta.
    ['PANAL WINNY ETAPA 3', 'PANAL WINNY', 'Etapa 3'],
    ['PANAL WINNY ETAPA 0', 'PANAL WINNY', 'Etapa 0'],

    // El numeral de los velones.
    ['VELON BLANCO SAN JORGE # 004', 'JORGE SAN VELON', 'Blanco # 004'],

    // Hombre y mujer son dos productos, no dos tamaños.
    ['DESODORANTE REXONA CLINICAL HOMBRE 8,5 GR', 'CLINICAL DESODORANTE REXONA', 'Hombre 8,5 g'],
    ['DESODORANTE REXONA CLINICAL MUJER 8,5 GR', 'CLINICAL DESODORANTE REXONA', 'Mujer 8,5 g'],
]);

it('no confunde el numero de la medida con el de la variante', function () {
    /*
     * «ORIGINAL 2.25» es una medida sin unidad. Buscando el número después de
     * partir la puntuación quedaba «ORIGINAL 2 25» y el «2» pasaba por número
     * de variante: la etiqueta salía «Original 2 2,25 L», con el número
     * repetido y la variante inventada.
     */
    [, $etiqueta] = agrupador()->leer('GASEOSA COCA COLA ORIGINAL 2.25');

    expect($etiqueta)->toBe('Original 2,25 L');
});

/* ------------------------------------------------------------------ */
/* LAS TRES REGLAS                                                     */
/* ------------------------------------------------------------------ */

it('agrupa las presentaciones de un mismo producto', function () {
    $grupos = agrupador()->agrupar([
        producto(1, 'SALSA DE TOMATE SAN JORGE  *170 GR'),
        producto(2, 'SALSA DE TOMATE SAN JORGE 400G'),
        producto(3, 'SALSA TOMATE SAN JORGE  *1000 GR'),
    ]);

    expect($grupos)->toHaveCount(1);
    expect($grupos[0]['miembros'])->toHaveCount(3);

    $etiquetas = array_column($grupos[0]['miembros'], '_etiqueta');
    expect($etiquetas)->toEqualCanonicalizing(['170 g', '400 g', '1000 g']);
});

it('NO agrupa cuando el nucleo es de una sola palabra', function () {
    /*
     * «MORA» es la fruta y «MORA» es el sabor. Con un núcleo de una palabra
     * la fruta acabaría dentro del grupo de los jugos, y pedir mora traería
     * un jugo.
     */
    $grupos = agrupador()->agrupar([
        producto(1, 'MORA'),
        producto(2, 'MORA 500 GR'),
    ]);

    expect($grupos)->toBeEmpty();
});

it('NO agrupa productos de categorias distintas', function () {
    /*
     * Si dos nombres se parecen pero están en secciones distintas, el parecido
     * fue casualidad. Acá «CREMA DENTAL» (farmacia) y «CREMA DE LECHE»
     * (lácteos) comparten palabra y no comparten nada más.
     */
    $grupos = agrupador()->agrupar([
        producto(1, 'ACEITE RIQUISIMO 250 ML', categoria: 1),
        producto(2, 'ACEITE RIQUISIMO 500 ML', categoria: 5),
    ]);

    expect($grupos)->toBeEmpty();
});

it('NO agrupa cuando dos variantes tendrian la misma etiqueta', function () {
    /*
     * Dos miembros con la misma etiqueta no son dos variantes: o el catálogo
     * tiene un duplicado o la etiqueta no distingue. En los dos casos el
     * selector quedaría con dos chips iguales y no habría forma de elegir.
     */
    $grupos = agrupador()->agrupar([
        producto(1, 'ARROZ DIANA 500 GR'),
        producto(2, 'ARROZ DIANA 500G'),
    ]);

    expect($grupos)->toBeEmpty();
});

it('NO agrupa cuando alguna variante no declara presentacion', function () {
    /*
     * Sin etiqueta no hay chip que pintar, así que ese miembro sería
     * inalcanzable dentro del grupo: estaría en el catálogo y no habría manera
     * de llegar a él desde la tarjeta.
     */
    $grupos = agrupador()->agrupar([
        producto(1, 'SALSA DE TOMATE FRUCO'),
        producto(2, 'SALSA DE TOMATE FRUCO 80 GR'),
    ]);

    expect($grupos)->toBeEmpty();
});

it('NO agrupa un producto solo', function () {
    $grupos = agrupador()->agrupar([producto(1, 'PANELA GRANDE')]);

    expect($grupos)->toBeEmpty();
});

/* ------------------------------------------------------------------ */
/* LO QUE SE GUARDA DEL GRUPO                                          */
/* ------------------------------------------------------------------ */

it('nombra el grupo sin nada que distinga a una variante de otra', function () {
    /*
     * No basta con quitarle la medida al nombre más corto. «PAÑAL WINNY ETAPA
     * 0» daba un grupo llamado «Pañal Winny etapa 0» cuyos chips eran las
     * etapas 0 a 5, y «GASEOSA POSTOBON UVA 1.5 LT» uno llamado «Postobón Uva»
     * que contenía manzana y naranja: el título anunciaba una de sus variantes
     * como si fuera el todo.
     */
    $grupos = agrupador()->agrupar([
        producto(1, 'GASEOSA COCA COLA ORIGINAL 1LT'),
        producto(2, 'GASEOSA COCA COLA ORIGINAL 250 ML'),
    ]);

    expect($grupos)->toHaveCount(1);
    expect($grupos[0]['name'])->toBe('COCA COLA');
});

it('quita la palabra de variante del nombre del grupo', function () {
    $grupos = agrupador()->agrupar([
        producto(1, 'PANAL WINNY ETAPA 0', categoria: 5),
        producto(2, 'PANAL WINNY ETAPA 3', categoria: 5),
    ]);

    expect($grupos[0]['name'])->toBe('PANAL WINNY');
});

it('quita el sabor del nombre cuando el grupo tiene varios', function () {
    $grupos = agrupador()->agrupar([
        producto(1, 'GASEOSA POSTOBON UVA 1.5 LT', categoria: 2),
        producto(2, 'GASEOSA POSTOBON MANZANA 400ML', categoria: 2),
    ]);

    expect($grupos[0]['name'])->toBe('POSTOBON');
});

it('deja la marca del grupo en null cuando sus variantes no coinciden', function () {
    /*
     * Elegir la marca de uno de los miembros sería inventarse un dato. Null
     * dice la verdad: el grupo no tiene una sola marca.
     */
    $grupos = agrupador()->agrupar([
        producto(1, 'SALSA DE TOMATE SAN JORGE  *170 GR', marca: 'San Jorge'),
        producto(2, 'SALSA DE TOMATE SAN JORGE 400G', marca: 'Fruco'),
    ]);

    expect($grupos[0]['brand'])->toBeNull();
});

it('toma como imagen del grupo la primera que tenga una variante', function () {
    $grupos = agrupador()->agrupar([
        producto(1, 'SALSA DE TOMATE SAN JORGE  *170 GR', imagen: null),
        producto(2, 'SALSA DE TOMATE SAN JORGE 400G', imagen: 'https://ejemplo/foto.jpg'),
    ]);

    expect($grupos[0]['image'])->toBe('https://ejemplo/foto.jpg');
});

/* ------------------------------------------------------------------ */
/* LO QUE SE ARREGLÓ AL REVISAR LOS GRUPOS QUE SALIERON                 */
/* ------------------------------------------------------------------ */

it('junta lo que dos catalogos escriben distinto', function () {
    /*
     * El catálogo viejo escribe «GASEOSA COCA COLA ORIGINAL 1LT» y el que
     * llegó del punto de venta de una tienda escribe «COCA COLA 3 LT». Esa
     * palabra de más partía la Coca-Cola en dos tarjetas, y el comprador veía
     * dos listas de tamaños donde hay una.
     */
    $grupos = agrupador()->agrupar([
        producto(1, 'GASEOSA COCA COLA ORIGINAL 1LT', categoria: 2),
        producto(2, 'COCA COLA 3 LT', categoria: 2),
        producto(3, 'COCA COLA PET 400 ML', categoria: 2),
    ]);

    expect($grupos)->toHaveCount(1);
    expect($grupos[0]['miembros'])->toHaveCount(3);
});

it('el envase distingue pero no parte el grupo', function () {
    /*
     * «PET» es el envase, no el producto. Antes dejaba «COCA COLA PET 400 ML»
     * en un grupo propio; ahora va al chip, que es donde el comprador lo usa
     * para elegir.
     */
    [, $etiqueta] = agrupador()->leer('COCA COLA PET 400 ML');

    expect($etiqueta)->toBe('Pet 400 ml');
});

it('lee bien los nombres con enie', function () {
    /*
     * `iconv` con TRANSLIT convierte «PIÑA» en «PI~NA», y al limpiar símbolos
     * eso quedaba en «PI NA»: «HIT NARANJA PIÑA» daba el núcleo «HIT NA PI» y
     * no agrupaba con nada. En un catálogo colombiano la Ñ no es un caso raro.
     */
    [$nucleo, $etiqueta] = agrupador()->leer('HIT NARANJA PIÑA 1.5');

    expect($nucleo)->toBe('HIT');
    expect($etiqueta)->toBe('Naranja Pina 1,5 L');
});

it('NO agrupa por una palabra que solo nombra una clase de producto', function () {
    /*
     * «AGUA» juntaba el agua de 5 litros con «AGUA MANZANA», que es una
     * gaseosa de Postobón. Dos productos del mismo tipo y distinta marca no
     * son el mismo producto en dos presentaciones.
     */
    $grupos = agrupador()->agrupar([
        producto(1, 'AGUA 5 LITROS', categoria: 2),
        producto(2, 'AGUA MANZANA 600ML', categoria: 2),
    ]);

    expect($grupos)->toBeEmpty();
});

it('SI agrupa por una sola palabra cuando esa palabra es la marca', function () {
    /*
     * La regla anterior pedía dos palabras de núcleo, y eso dejaba fuera los
     * doce sabores de Hit y los ocho de Postobón: en esos el nombre de la
     * marca ya identifica el producto y lo demás es sabor y tamaño.
     */
    $grupos = agrupador()->agrupar([
        producto(1, 'HIT FRUTAS TROPICALES 200 ML', categoria: 2),
        producto(2, 'HIT MANGO 1.5', categoria: 2),
    ]);

    expect($grupos)->toHaveCount(1);
    expect($grupos[0]['name'])->toBe('HIT');
});
