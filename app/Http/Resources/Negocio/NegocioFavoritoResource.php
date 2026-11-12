<?php

declare(strict_types=1);

namespace App\Http\Resources\Negocio;

use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UN NEGOCIO EN LA LISTA DE FAVORITOS. Veintidós claves.
 *
 * Es el cuarto miembro de la familia de listados de negocio, y el que se
 * separó del resto. NO usa `NegocioDelListadoResource` —aunque comparta
 * diecinueve claves con él— y la razón está medida, no supuesta:
 *
 *   solo en favoritos   favorite_id
 *   le FALTAN            delivery_time_minutes, accepting_orders,
 *                        is_affiliated
 *   y el ORDEN es otro   `favorite_id` primero, y `description` tras `name`
 *                        en vez de tras `address`
 *
 * Unificarlas obligaría a reordenar las claves de una de las dos respuestas o
 * a añadirle tres campos a esta. Lo segundo cambia lo que recibe la app
 * publicada; lo primero cambia los bytes del JSON sin cambiar su significado,
 * y mientras la regla de este trabajo sea «la respuesta no cambia», tampoco
 * se hace a la ligera.
 *
 * LAS CONSECUENCIAS DE LO QUE LE FALTA, que es lo que hay que decidir:
 *
 *   accepting_orders   desde favoritos la app NO PUEDE SABER si la tienda
 *                      está en pausa. En los otros listados y en la ficha sí,
 *                      y el comentario de la ficha dice para qué sirve: poder
 *                      decir «no está recibiendo pedidos ahora» y apagar el
 *                      carrito en vez de dejar pedir para fallar al final.
 *                      Desde la lista de favoritos, eso no se puede.
 *   is_affiliated      no viaja, y de ahí viene lo de abajo.
 *
 * Y EL PRECIO, que es el hallazgo que ya estaba escrito en el controlador:
 * esta forma monta los productos con `ProductoFavoritoResource`, que NO
 * comprueba la afiliación, al contrario que los dos listados del catálogo. La
 * cuenta exacta está en la cabecera de esa clase. Se conserva como estaba: es
 * un asunto de producto, no una errata.
 *
 * `owners` pasa por `PropietarioResource` igual que los otros tres. No fue
 * siempre así: la fuga de la cédula del tendero se cerró en los dos listados
 * del catálogo y ESTE se quedó sin tocar, así que bastaba marcar la tienda
 * como favorita para volver a leerlo todo. Tenerlos los cuatro en clases
 * hermanas es justamente para que no pueda volver a pasar.
 */
class NegocioFavoritoResource extends JsonResource
{
    /**
     * @param  array{distance_km: float|null, delivery_fee: mixed, in_range: mixed}  $reparto
     *                                                                                         Tres valores y no cuatro: acá no viaja `delivery_time_minutes`.
     * @param  array<int, mixed>  $productos  Ya resueltos; la clase NO comprueba la afiliación.
     * @param  array<int, mixed>  $resenas  Ya resueltas, o `[]` en modo ligero.
     */
    public function __construct(
        Business $negocio,
        private readonly int $favoritoId,
        private readonly array $reparto,
        private readonly array $productos,
        private readonly array $resenas,
    ) {
        parent::__construct($negocio);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Business $b */
        $b = $this->resource;

        return [
            'favorite_id' => $this->favoritoId,
            'business_id' => $b->busines_id,
            'latitude' => $b->latitude !== null ? (float) $b->latitude : null,
            'longitude' => $b->longitude !== null ? (float) $b->longitude : null,
            'distance_km' => $this->reparto['distance_km'],
            'delivery_fee' => $this->reparto['delivery_fee'],
            'in_range' => $this->reparto['in_range'],
            'name' => $b->name,
            // La tarjeta la pinta debajo del nombre; sin ella decía «Sin
            // descripción» de una tienda que sí la tiene.
            'description' => $b->description,
            'phone' => $b->phone,
            'address' => $b->address,
            'qualification' => (float) $b->qualification,
            'razon_social' => $b->razonSocial_DCD,
            'NIT' => $b->NIT,
            'logo' => $b->logo ?? 'https://example.com/default-logo.png',
            'state' => (bool) $b->state,
            'type' => $b->type,
            'municipality' => $b->municipality ? [
                'id' => $b->municipality->id,
                'name' => $b->municipality->name,
            ] : null,
            'owner_count' => $b->owners->count(),
            'owners' => PropietarioResource::collection($b->owners)->resolve(),
            'products' => $this->productos,
            'reviews' => $this->resenas,
        ];
    }
}
