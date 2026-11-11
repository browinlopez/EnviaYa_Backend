<?php

declare(strict_types=1);

namespace App\Http\Resources\Producto;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UN PRODUCTO EN LA LISTA DE FAVORITOS. Y lleva una diferencia que hay que
 * decidir, no una variante de estilo.
 *
 * Era el `map` en linea de `FavoriteController::myFavorites()`. Tiene los
 * MISMOS SIETE CAMPOS que `ProductoDelNegocioResource` —comprobado contra la
 * respuesta real— pero calcula el precio de otra manera:
 *
 *   ProductoDelNegocioResource  price = $userId && $afiliado
 *                                        ? ($pivot->price ?? 0)
 *                                        : 0
 *   aqui                        price = $pivot->price ?? null
 *
 * O SEA QUE FAVORITOS NO COMPRUEBA LA AFILIACION. La regla que documenta
 * `ProductoDelNegocioResource` —«el precio solo viaja si quien pregunta esta
 * afiliado a esa tienda; es lo que impide leer los precios de un negocio con
 * el que no se tiene relacion»— aqui no se aplica, y marcar una tienda como
 * favorita no exige afiliacion. Tambien cae a `null` en vez de a `0`, asi que
 * la app no puede distinguir «sin precio» de «bloqueado» por este endpoint.
 *
 * Y NO ES LA PRIMERA VEZ EN ESTE MISMO METODO. El comentario que quedo arriba,
 * en el controlador, dice de los datos del propietario: «la misma fuga se
 * cerro en los dos listados del catalogo y ESTE se quedo sin tocar; bastaba
 * con marcar la tienda como favorita para volver a leerlo todo». Se cerro la
 * de los propietarios y la del precio se quedo, en la misma respuesta.
 *
 * SE CONSERVA TAL CUAL, a proposito: cambiar el precio a 0 cuando no hay
 * afiliacion es cambiar lo que recibe una app publicada en las dos tiendas, y
 * decidir si eso es un arreglo o una regresion no me toca a mi. Lo que se gana
 * moviendolo es que la diferencia esta medida, escrita y al lado de la otra.
 */
class ProductoFavoritoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $product = $this->resource;

        return [
            'product_id' => $product->products_id,
            'name' => $product->name,
            'description' => $product->description,
            'category_id' => $product->category_id,
            'image' => $product->image,
            'state' => (bool) $product->state,
            // Sin comprobar afiliacion, y cayendo a null. Ver la cabecera.
            'price' => $product->pivot->price ?? null,
        ];
    }
}
