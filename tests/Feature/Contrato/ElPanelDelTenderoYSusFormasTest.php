<?php

declare(strict_types=1);

use App\Http\Resources\Chat\ChatDeLaListaResource;
use App\Http\Resources\Chat\ChatResource;
use App\Http\Resources\Negocio\MiNegocioResource;
use App\Http\Resources\Producto\ProductoDeLaTiendaResource;
use App\Http\Resources\Usuario\NegocioVinculadoResource;
use App\Models\Business;
use App\Models\Chat\Chat;
use App\Models\Product\Product;
use App\Models\Product\ProductBusiness;
use Illuminate\Database\Eloquent\Collection as Eloquentes;

/**
 * LAS FORMAS DEL PANEL DEL TENDERO, Y UNA REGLA QUE SE CUMPLE A MEDIAS.
 *
 * Cuatro formas que vivían dentro de un método pasan a su clase. La primera
 * prueba de este archivo es la que importa, y no es sobre la migración.
 *
 * `NegocioRequest` documenta el tope de crédito con estas palabras: «solo lo
 * pone el equipo; EL PANEL DEL TENDERO NO LO RECIBE». Medido:
 *
 *   MiNegocioResource         la tienda a su propio dueño      NO lo manda
 *   NegocioVinculadoResource  la misma tienda, en el login     SÍ lo manda
 *
 * Así que la regla existe, está implementada en un sitio y se incumple en
 * otro. Mientras las dos formas vivieran una en un método y otra en una
 * clase, compararlas era leerlas y acordarse. Acá está medido, y si alguien
 * añade el campo al panel —o lo quita del login— esta prueba lo dice.
 *
 * Lo del login no se arregla acá: quitar un campo de la respuesta del login
 * cambia lo que reciben dos apps publicadas en las tiendas, y si alguna
 * pantalla lo lee, quitarlo la rompe. Es una decisión de producto.
 */
function unaTiendaPropia(): Business
{
    $b = new Business;
    $b->setRawAttributes([
        'busines_id' => 3,
        'name' => 'Tienda Ana',
        'phone' => '3009999999',
        'address' => 'Calle 2',
        'description' => 'Abarrotes',
        'NIT' => '900123456',
        'razonSocial_DCD' => 'Tienda Ana SAS',
        'logo' => 'logo.png',
        'max_courier_cash' => '200000.00',
        'credit_debt_cap' => '1000000.00',
        'type' => 1,
        'state' => 1,
        'accepting_orders' => 1,
        'qualification' => '4.80',
        'municipality_id' => 1,
        'latitude' => '4.6097100',
        'longitude' => '-74.0817500',
    ], true);

    return $b;
}

it('el panel del tendero NO recibe el tope de credito, como dice la regla', function () {
    $publicado = (new MiNegocioResource(unaTiendaPropia()))->resolve();

    expect($publicado)->not->toHaveKey('credit_debt_cap');

    /* CONTROL: sí recibe lo que es suyo de configurar. Sin esto, la
       afirmación de arriba pasaría también con una lista vacía. */
    expect($publicado)->toHaveKey('max_courier_cash', 200000.0);
});

it('pero el login SI lo manda, y por eso la regla se cumple a medias', function () {
    $publicado = (new NegocioVinculadoResource(unaTiendaPropia()))->resolve();

    /* La misma tienda, en la respuesta del login. MEDIDO, NO ARREGLADO: va
       con lista blanca escrita, así que no es una columna que se publique
       sola; es una decisión pendiente. Si se toma y se quita, esta prueba se
       pone roja y hay que borrarla: será la señal. */
    expect($publicado)->toHaveKey('credit_debt_cap');
});

it('el producto de la tienda lleva las CUATRO claves de configuracion del peso', function () {
    $p = new Product;
    $p->setRawAttributes([
        'products_id' => 7,
        'name' => 'Queso',
        'barcode' => '7700000000017',
        'brand' => 'Del campo',
        'description' => 'Campesino',
        'image' => 'queso.png',
        'state' => 1,
    ], true);
    $p->setRelation('category', null);

    $pb = new ProductBusiness;
    $pb->setRawAttributes([
        'price' => '18000.00',
        'amount' => 0,
        'sale_unit' => 'kg',
        'stock_weight' => '12.500',
        'min_qty' => '0.250',
        'step_qty' => '0.250',
        'expiration_date' => '2027-01-31',
        'qualification' => null,
    ], true);
    $pb->setRelation('product', $p);

    $publicado = (new ProductoDeLaTiendaResource($pb, otrasTiendas: 2))->resolve();

    /* CUATRO, y son la CONFIGURACIÓN: cómo vende el tendero esa referencia.
       No se confundan con las CINCO del renglón de un pedido —`sale_unit`,
       `pedido_por`, `qty_requested`, `qty_final`, `line_total`—, que son lo
       que se pidió y lo que se pesó. Mismo asunto, dos momentos. */
    foreach (['sale_unit', 'stock_weight', 'min_qty', 'step_qty'] as $clave) {
        expect($publicado)->toHaveKey($clave);
    }
    expect($publicado)->not->toHaveKey('qty_final');
    expect($publicado)->not->toHaveKey('line_total');

    /* `sin_precio` sale calculado: con precio 18000 es falso. Los que llegan
       por copia de otra tienda nacen en cero y el panel tiene que poder
       señalarlos, porque son la lista de trabajo del tendero. */
    expect($publicado['sin_precio'])->toBeFalse();
    expect($publicado['compartido'])->toBe(2);
});

it('la lista de chats no se trae el historial de cada conversacion', function () {
    $chat = new Chat;
    $chat->setRawAttributes([
        'chat_id' => 5,
        'type' => 'private',
        'created_at' => '2026-11-12 10:00:00',
    ], true);
    $chat->setRelation('participants', new Eloquentes);
    $chat->setRelation('messages', new Eloquentes);

    $lista = (new ChatDeLaListaResource($chat))->resolve();
    $abierto = (new ChatResource($chat))->resolve();

    /* La lista lleva el último mensaje aplastado en tres campos, para la
       vista previa; el chat abierto lleva `messages`. Traerse el historial
       completo de cada conversación para dibujar una lista es justo lo que
       esta separación evita. */
    expect($lista)->not->toHaveKey('messages');
    expect($lista)->toHaveKey('last_message');
    expect($abierto)->toHaveKey('messages');
    expect($abierto)->not->toHaveKey('last_message');
});
