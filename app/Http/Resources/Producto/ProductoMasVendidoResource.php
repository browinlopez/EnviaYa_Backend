<?php

declare(strict_types=1);

namespace App\Http\Resources\Producto;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UN PRODUCTO DE LOS MAS VENDIDOS. La sexta y ultima de las seis formas.
 *
 * Era el `map` de `DestacadosDeProductoController::mostPopularProducts()`, que
 * sirve `POST /v1/product/topProductsBusiness`. Ocho campos, y es la unica que
 * trae `total_ordered`.
 *
 * SU PRECIO NO COMPRUEBA NADA, Y AQUI SI ES UN HALLAZGO. El endpoint pide
 * sesion y NADA MAS: no comprueba pertenencia —al contrario que
 * `/product/index`, que contesta «Ese negocio no es tuyo»— ni afiliacion
 * —al contrario que la vitrina y la ficha—. Comprobado leyendo el metodo: no
 * hay ninguna de las dos guardas.
 *
 * O sea que cualquiera con una cuenta puede pedir los mas vendidos de
 * CUALQUIER negocio, con sus precios actuales y cuanto se ha pedido de cada
 * uno. Eso es el surtido estrella de una tienda y lo que cobra por el.
 *
 * `total_ordered` suma `amount`, que vale 1 en las lineas por peso: para esos
 * productos el numero cuenta «pedidos que lo llevaron», no cantidad. Esta
 * decidido asi y documentado en el plan del peso —rankear por dinero cambiaria
 * el orden de un listado ya publicado—.
 *
 * SE CONSERVA TAL CUAL. Anadirle la comprobacion de afiliacion es un cambio de
 * comportamiento en un endpoint publicado, y decidir si el ranking de una
 * tienda es publico es de producto, no mio. Queda medido y con prueba.
 */
class ProductoMasVendidoResource extends JsonResource
{
    public function __construct(
        mixed $item,
        private readonly float $precio,
    ) {
        parent::__construct($item);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $item = $this->resource;
        $prices = [$item->product_id => $this->precio];

        return [
            'product_id' => $item->product->products_id,
            'name' => $item->product->name,
            'description' => $item->product->description,
            'category_id' => $item->product->category_id,
            'image' => $item->product->image,
            'state' => $item->product->state,
            'price' => (float) ($prices[$item->product_id] ?? 0),
            'total_ordered' => (int) $item->total_ordered, // cantidad total pedida
        ];
    }
}
