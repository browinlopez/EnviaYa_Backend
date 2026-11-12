<?php

declare(strict_types=1);

namespace App\Http\Resources\Producto;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EL PRODUCTO EN EL CATALOGO DE SU PROPIA TIENDA. La quinta de seis formas.
 *
 * Era el `map` de `ProductController::index()`, que sirve
 * `POST /v1/product/index`. Catorce campos: la mas rica de las seis.
 *
 * SU PRECIO NO COMPRUEBA LA AFILIACION, Y AQUI ESTA BIEN. Escribe
 * `$product->pivot->price` a secas, y ademas manda `amount` —las existencias—
 * y `qualification`. No es una fuga porque el endpoint comprueba la
 * PERTENENCIA: con la sesion de otro contesta «Ese negocio no es tuyo» con un
 * 403. Comprobado pidiendolo con la sesion de un comprador.
 *
 * Es su propio surtido: el tendero tiene que ver su precio y su existencia.
 * La regla de la afiliacion protege el precio de UNA TIENDA FRENTE A OTROS, no
 * frente a su dueño.
 *
 * `extra` y `business_type` tampoco estan en ninguna de las otras cinco: son
 * los campos del tipo de negocio —lo que una farmacia guarda y una tienda
 * no— y los resuelve `ProductTypeSchema`. Se RECIBEN resueltos porque el
 * controlador precarga todas las relaciones de todos los tipos en una
 * consulta; resolverlo aqui serian varias por producto.
 */
class ProductoDelCatalogoPropioResource extends JsonResource
{
    public function __construct(
        mixed $producto,
        private readonly mixed $tipoDeNegocio,
        private readonly mixed $extra,
    ) {
        parent::__construct($producto);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $product = $this->resource;
        $business = (object) ['type' => $this->tipoDeNegocio];
        $extraData = $this->extra;

        return [
            'product_id' => $product->products_id,
            'name' => $product->name,
            'description' => $product->description,
            'category' => $product->category ? [
                'category_id' => $product->category->category_id,
                'name' => $product->category->name,
            ] : null,
            'image' => $product->image,
            'state' => $product->state,
            /* La presentacion y de que tarjeta cuelga. Ver
               `AgrupadorDeVariantes`. Campos nuevos: una app vieja los
               ignora y sigue igual. */
            'variante' => $product->variante,
            'group' => $product->group ? [
                'group_id' => (int) $product->group->group_id,
                'name' => $product->group->name,
                'image' => $product->group->image,
            ] : null,
            'price' => $product->pivot->price,
            'amount' => $product->pivot->amount,
            'qualification' => $product->pivot->qualification,
            'business_type' => $business->type,
            'extra' => $extraData, // datos extra según tipo
        ];
    }
}
