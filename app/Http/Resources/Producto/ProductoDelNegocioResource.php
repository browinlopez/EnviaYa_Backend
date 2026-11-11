<?php

declare(strict_types=1);

namespace App\Http\Resources\Producto;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UN PRODUCTO DEL CATALOGO DE UNA TIENDA.
 *
 * Era `NegocioParaLaApp::producto()`, movida aqui sin tocar un campo.
 *
 * EL PRECIO ES LA REGLA DE ESTA CLASE, y por eso lleva dos argumentos de
 * construccion en vez de leerlos de la peticion: solo viaja si quien pregunta
 * esta AFILIADO a esa tienda. Sin sesion o sin afiliacion va en 0, y la app lo
 * pinta como bloqueado. Es lo que impide leer los precios de un negocio con el
 * que no se tiene relacion.
 *
 * Se pasan explicitos —`afiliado` y `userId`— y no se deducen aqui de
 * `$request->user()` a proposito: quien sabe si hay afiliacion es el
 * controlador, que ya hizo esa consulta para todo el listado. Deducirlo dentro
 * del Resource seria una consulta por producto, que es un N+1 de los que este
 * repositorio ya ha pagado dos veces.
 */
class ProductoDelNegocioResource extends JsonResource
{
    public function __construct(
        mixed $producto,
        private readonly bool $afiliado,
        private readonly mixed $userId,
    ) {
        parent::__construct($producto);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $producto = $this->resource;
        $afiliado = $this->afiliado;
        $userId = $this->userId;

        return [
            'product_id' => $producto->products_id,
            'name' => $producto->name,
            'description' => $producto->description,
            'category_id' => $producto->category_id,
            'image' => $producto->image,
            'state' => (bool) $producto->state,
            'price' => $userId && $afiliado ? ($producto->pivot->price ?? 0) : 0,
        ];
    }

    /**
     * El catalogo entero de un negocio, con la misma regla de precio.
     *
     * Era `NegocioParaLaApp::productos()`. Devuelve un array y no una
     * `ResourceCollection` porque los tres sitios que lo usan lo meten en una
     * clave de una respuesta mas grande, y una coleccion ahi anadiria su
     * propio envoltorio.
     *
     * @return list<array<string, mixed>>
     */
    public static function deLaTienda(mixed $negocio, bool $afiliado, mixed $userId): array
    {
        return $negocio->products
            ->map(fn ($p) => (new self($p, $afiliado, $userId))->resolve())
            ->all();
    }
}
