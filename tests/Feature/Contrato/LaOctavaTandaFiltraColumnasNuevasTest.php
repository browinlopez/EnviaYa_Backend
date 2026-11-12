<?php

declare(strict_types=1);

use App\Http\Resources\Chat\MensajeResource;
use App\Http\Resources\Comprobante\FacturaResource;
use App\Http\Resources\Producto\CategoriaDeProductoResource;
use App\Http\Resources\Usuario\AvisoResource;
use App\Http\Resources\Usuario\DomiciliarioDelAltaResource;
use App\Models\Chat\Message;
use App\Models\Domiciliary;
use App\Models\Invoice\Invoice;
use App\Models\Notification;
use App\Models\Product\Category;

/**
 * LOS CINCO SITIOS QUE SOLTABAN EL MODELO ENTERO, Y LO QUE CAMBIA.
 *
 * Cinco respuestas devolvían el modelo pelado, sin lista de claves:
 *
 *     'message' => $message,           ChatController::updateMessage()
 *     'category' => $category,         CategoryController::update()
 *     'notifications' => $avisos,      NotificacionesController::getNotifications()
 *     'invoice' => $f,                 FacturasApiController::anular()
 *     'domiciliary' => $domiciliary,   DomiciliaryController::update()
 *
 * La forma de esas respuestas era «las columnas que tenga la tabla». Una
 * migración que añadiera un campo —una nota interna, un identificador de otro
 * sistema, una marca de proceso— lo convertía en campo público de la API sin
 * que nadie lo decidiera y sin que nada avisara.
 *
 * LAS LISTAS NO SE ESCRIBIERON A OJO. Salen de `toArray()` de una fila real,
 * con `scripts/` aparte, porque son 53 nombres de columna entre las cinco y
 * transcribirlos a mano es donde se cae uno: lo que se cae es un dato que una
 * pantalla deja de recibir, y no falla nada, simplemente falta.
 *
 * CÓMO SE COMPRUEBA, Y POR QUÉ ASÍ. No se mira la constante del Resource
 * —comparar una lista con ella misma no comprueba nada—: se carga el modelo
 * con `setRawAttributes()`, que es exactamente como Eloquent llena un modelo
 * al leer una fila, incluyendo una columna que la lista NO tiene. Entonces:
 *
 *   1. el Resource no la saca            —lo que esta tanda arregla—
 *   2. el `toArray()` crudo SÍ la saca   —el grupo de control—
 *
 * El punto 2 es el que hace que esto valga algo. Sin él, un Resource que
 * devolviera un array vacío pasaría las cinco pruebas: «no sale la columna»
 * es verdad cuando no sale nada. Y de paso deja medido, en la propia prueba,
 * qué publicaba el crudo antes del cambio.
 */

/**
 * Un modelo lleno como lo llena Eloquent al leer, más una columna que la
 * lista blanca no conoce.
 *
 * @param  class-string  $clase
 */
function conUnaColumnaDeMas(string $clase, array $campos): object
{
    $m = new $clase;
    $m->setRawAttributes($campos + ['columna_recien_migrada' => 'no deberia salir'], true);

    return $m;
}

dataset('los cinco sitios', [
    'el mensaje que devuelve updateMessage' => [
        Message::class,
        MensajeResource::class,
        [
            'message_id' => 1, 'chat_id' => 2, 'user_id' => 3,
            'role_id' => 1, 'content' => '"hola"', 'created_at' => '2026-11-12 10:00:00',
        ],
        'chat_id',
    ],
    'la categoria de producto que devuelve update' => [
        Category::class,
        CategoriaDeProductoResource::class,
        [
            'category_id' => 7, 'name' => 'Lácteos', 'description' => 'Leche y queso',
            'state' => 1, 'business_category_id' => 2,
        ],
        'name',
    ],
    'el aviso de la campana' => [
        Notification::class,
        AvisoResource::class,
        [
            'notification_id' => 4, 'user_id' => 9, 'tipo' => 'pedido',
            'message' => 'Tu pedido va en camino', 'datos' => '{"orderSales_id":3}',
            'read' => 0, 'date' => '2026-11-12 10:00:00', 'state' => 1,
        ],
        'message',
    ],
    'el comprobante del panel' => [
        Invoice::class,
        FacturaResource::class,
        [
            'invoice_id' => 11, 'orderSales_id' => 3, 'busines_id' => 2,
            'invoice_number' => 'A-0011', 'total' => '28000.00', 'state' => 1,
            'void_reason' => null, 'snapshot' => '{}',
        ],
        'invoice_number',
    ],
    'el domiciliario que devuelve el alta' => [
        Domiciliary::class,
        DomiciliarioDelAltaResource::class,
        [
            'domiciliary_id' => 5, 'user_id' => 9, 'available' => 1,
            'document' => '1020304050', 'contract_city' => 'Bogotá',
            'qualification' => '4.80', 'state' => 1,
        ],
        'document',
    ],
]);

it('no saca una columna que la lista no tiene', function (
    string $modelo,
    string $resource,
    array $campos,
    string $clavePropia,
) {
    $fila = conUnaColumnaDeMas($modelo, $campos);

    $publicado = (new $resource($fila))->resolve();

    expect($publicado)->not->toHaveKey('columna_recien_migrada');

    /* Y que sigue sacando lo suyo: una lista blanca que lo tirara todo
       también pasaría la afirmación de arriba. */
    expect($publicado)->toHaveKey($clavePropia, $campos[$clavePropia]);
})->with('los cinco sitios');

it('CONTROL: el modelo crudo SI la saca, que es lo que se publicaba antes', function (
    string $modelo,
    string $resource,
    array $campos,
    string $clavePropia,
) {
    $fila = conUnaColumnaDeMas($modelo, $campos);

    /* Esto es lo que devolvía el controlador antes de esta tanda. Si esta
       afirmación fallara, la prueba de arriba no significaría nada: estaría
       comprobando que no sale una columna que tampoco entró. */
    expect($fila->toArray())->toHaveKey('columna_recien_migrada');
})->with('los cinco sitios');
