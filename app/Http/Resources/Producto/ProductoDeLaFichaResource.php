<?php

declare(strict_types=1);

namespace App\Http\Resources\Producto;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EL PRODUCTO EN LA FICHA DE UN NEGOCIO. La cuarta de seis formas.
 *
 * Era el `map` de `ConsultaDeNegociosController::show()`, movido aqui sin
 * tocar un campo. Doce campos.
 *
 * ES LA UNICA DE LAS SEIS QUE TRAE `promocion`, y ese campo tiene una regla
 * dentro: nunca se pone sobre lo que se vende al peso, porque el motor de
 * descuentos excluye esas lineas y la etiqueta prometeria una rebaja que el
 * carrito no da. Esa exclusion estuvo rota hasta el 11 de noviembre de 2026
 * —`Business::products()` no traia `sale_unit`, asi que el filtro veia
 * siempre `unidad` y no excluia nada—; hay prueba.
 *
 * SU PRECIO COMPRUEBA LA AFILIACION PERO NO EL `userId`: escribe
 * `$estaAfiliado ? ($pivot->price ?? 0) : 0`, mientras la vitrina y los
 * destacados escriben `$userId && $afiliado ? ... : 0`. Sin sesion no hay
 * afiliacion posible, asi que en la practica coinciden; lo que no coincide es
 * el texto, y de los seis sitios tres comprueban y tres no.
 *
 * La promocion se RECIBE resuelta. `show()` trae las promociones vigentes de
 * la tienda en una consulta y un cierre decide cual alcanza a cada producto:
 * resolverlo dentro del Resource serian dos consultas por producto.
 */
class ProductoDeLaFichaResource extends JsonResource
{
    /** @param  callable(int): (array<string, mixed>|null)  $promoDe */
    public function __construct(
        mixed $producto,
        private readonly bool $estaAfiliado,
        private $promoDe,
    ) {
        parent::__construct($producto);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /*
         * Los nombres se conservan —`$product`, `$estaAfiliado`, `$promoDe`—
         * para que el cuerpo sea el mismo texto que habia en el controlador.
         */
        $product = $this->resource;
        $estaAfiliado = $this->estaAfiliado;
        $promoDe = $this->promoDe;

        return [
            'product_id' => $product->products_id,
            'name' => $product->name,
            'description' => $product->description,
            'category_id' => $product->category_id,
            // La ficha agrupa por nombre de categoría, igual que el
            // listado; con solo el id no podía pintar las secciones.
            'category' => $product->category?->name,
            'image' => $product->image,
            'state' => (bool) $product->state,
            'price' => $estaAfiliado ? ($product->pivot->price ?? 0) : 0,
            // Como se vende: por unidad o al peso. Ver arriba.
            'sale_unit' => $product->pivot->sale_unit ?? 'unidad',
            'min_qty' => $product->pivot->min_qty !== null ? (float) $product->pivot->min_qty : null,
            'step_qty' => $product->pivot->step_qty !== null ? (float) $product->pivot->step_qty : null,
            /*
                 * `null` cuando no hay ninguna. La app pinta la etiqueta
                 * solo si viene algo.
                 *
                 * Y NUNCA sobre lo que se vende al peso: el motor de
                 * descuentos excluye esas lineas --un «lleva 3, paga 2»
                 * sobre 1,18 kg no esta definido-- asi que la etiqueta
                 * prometeria una rebaja que el carrito no va a aplicar.
                 * Es peor que no ensenar nada: el cliente llega al total
                 * esperando otro numero.
                 */
            'promocion' => in_array($product->pivot->sale_unit ?? 'unidad', ['kg', 'libra', 'gramo'], true)
                ? null
                : $promoDe((int) $product->products_id),
        ];
    }
}
