<?php

declare(strict_types=1);

use App\Services\MediaService;

/**
 * LAS LISTAS DE ENTIDADES DE `MediaService` TIENEN QUE CUBRIR LO MISMO.
 *
 * `MediaService` reparte los archivos de seis clases de cosa —negocios,
 * productos, usuarios, conjuntos, categorías y banners— y esa lista está
 * escrita TRES veces:
 *
 *   RAICES                  dónde va cada carpeta, y qué entidades existen
 *   MODULOS                 qué módulo del panel manda sobre sus archivos
 *   el `match` de nombreDe  cómo se saca el rótulo de cada registro
 *
 * ESTO NO ES UNA PRECAUCIÓN ABSTRACTA: YA PASÓ. El docblock de `nombreDe()`
 * lo cuenta —«se dio de alta `banners` en las raíces y no en la resolución de
 * nombres, así que la ficha de un banner pedía sus archivos y recibía…»—. Se
 * arregló, y no quedó nada que impida que vuelva a pasar.
 *
 * Y LA FORMA EN QUE VUELVE A PASAR ES FEA. El `match` de `nombreDe()` NO
 * tiene `default`:
 *
 *     return match ($entidad) {
 *         'negocios' => ..., 'productos' => ..., ...
 *     };                                  // sin default
 *
 * Un `match` sin `default` que no encuentra rama lanza `UnhandledMatchError`,
 * que es un 500. Hoy no puede pasar porque `raizValida()` corre antes y lanza
 * una `RuntimeException` para lo que no esté en `RAICES` —un error con
 * mensaje, no una caída—, así que la puerta la guarda `RAICES`. Pero eso
 * significa que **el día que se añada una séptima entidad a `RAICES` sin
 * tocar el `match`, su ficha devuelve 500**, y lo hará en producción porque
 * nada lo dice antes.
 *
 * POR QUÉ NO SE ARREGLA PONIENDO UN `default`. Porque un `default => null`
 * convertiría el 500 en un rótulo vacío: la ficha de esa entidad saldría sin
 * nombre y nadie se enteraría. Un fallo ruidoso es mejor que uno callado, y
 * esta prueba lo adelanta a antes del despliegue, que es mejor que las dos.
 *
 * EL OTRO `match` SÍ LLEVA `default`, Y ESTÁ BIEN ASÍ.
 * `sincronizarPrincipal()` copia la URL a la columna que lee la app, y solo
 * cuatro de las seis entidades TIENEN columna de imagen: `usuarios` solo
 * admite documentos y la imagen de un banner vive en `media_files` —lo dice
 * `Banner`—. Así que ese `default => null` no es un descuido: es la lista de
 * las que no tienen dónde copiar. Por eso esta prueba NO lo exige completo.
 */
it('RAICES y MODULOS cubren exactamente las mismas entidades', function () {
    $raices = array_keys(MediaService::RAICES);
    $modulos = array_keys(MediaService::MODULOS);

    sort($raices);
    sort($modulos);

    expect($modulos)->toBe($raices);

    /* CONTROL: que las listas no estén vacías. Dos listas vacías son
       «exactamente iguales» y esta prueba pasaría sin comprobar nada. */
    expect(count($raices))->toBeGreaterThan(3);
});

it('el match de nombreDe tiene una rama por cada entidad de RAICES', function () {
    /*
     * Se leen las ramas del código, no se enumeran a mano: una lista escrita
     * aquí sería una CUARTA copia de lo mismo, y entonces esta prueba sería
     * parte del problema que viene a resolver.
     */
    $fuente = file_get_contents(base_path('app/Services/MediaService.php'));

    $desde = strpos($fuente, 'public function nombreDe');
    $hasta = strpos($fuente, 'public function carpeta');

    expect($desde)->toBeInt();
    expect($hasta)->toBeGreaterThan($desde);

    $cuerpo = substr($fuente, $desde, $hasta - $desde);

    preg_match_all("~^\s*'([a-z]+)' =>~m", $cuerpo, $m);

    $ramas = $m[1];
    $raices = array_keys(MediaService::RAICES);

    sort($ramas);
    sort($raices);

    /* CONTROL, y hace falta: si el patrón dejara de reconocer las ramas
       —porque alguien cambia el formato— vería cero y la comparación fallaría
       ruidosamente en vez de pasar en verde. Se comprueba igual, para que el
       motivo del rojo se distinga de una entidad que falta de verdad. */
    expect(count($ramas))->toBeGreaterThan(3);

    expect($ramas)->toBe($raices);
});

it('una entidad inventada da un error con mensaje, no un 500 de match', function () {
    /*
     * La prueba de que la puerta la guarda `raizValida()` y no el `match`. Si
     * algún día se invierte el orden de esas dos líneas, lo que recibe quien
     * pide una entidad rara pasa de un `RuntimeException` con su mensaje a un
     * `UnhandledMatchError`, que es un 500 pelado.
     */
    $media = app(MediaService::class);

    expect(fn () => $media->nombreDe('inventada', 1))
        ->toThrow(RuntimeException::class, 'Tipo de entidad no admitido');

    /* Y el control: con una entidad DE LA LISTA no lanza. Sin esto, lo de
       arriba pasaría también si `nombreDe()` lanzara siempre. */
    expect(fn () => $media->nombreDe('negocios', 999999))->not->toThrow(Throwable::class);
});

it('las listas de subconjuntos no nombran entidades que no existen', function () {
    $raices = array_keys(MediaService::RAICES);

    /* `UNA_SOLA_IMAGEN` y `SOLO_DOCUMENTOS` no tienen que estar completas
       —son subconjuntos a propósito— pero sí referirse a entidades reales.
       Una errata aquí no falla: simplemente deja de aplicarse la regla. */
    foreach (['UNA_SOLA_IMAGEN' => MediaService::UNA_SOLA_IMAGEN,
        'SOLO_DOCUMENTOS' => MediaService::SOLO_DOCUMENTOS] as $cual => $lista) {
        expect($lista)->not->toBe([]);

        foreach ($lista as $entidad) {
            expect($raices)->toContain($entidad);
        }
    }
});
