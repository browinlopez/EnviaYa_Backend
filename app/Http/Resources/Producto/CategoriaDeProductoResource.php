<?php

declare(strict_types=1);

namespace App\Http\Resources\Producto;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * UNA CATEGORIA DE PRODUCTO, la que devuelve el alta y la correccion.
 *
 * `CategoryController::update()` devolvia `'category' => $category` crudo
 * despues de guardar. Cinco columnas y ninguna que no deba verse, pero la
 * lista no estaba escrita en ningun sitio: la decidia la tabla.
 */
class CategoriaDeProductoResource extends JsonResource
{
    /**
     * Lo que esta respuesta publica HOY, medido contra una fila real.
     *
     * Cinco claves y ninguna delicada: el id, el nombre, la descripcion, el estado y el tipo de negocio al que pertenece.
     */
    private const CAMPOS = [
        'category_id',
        'name',
        'description',
        'state',
        'business_category_id',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return Arr::only($this->resource->toArray(), self::CAMPOS);
    }
}
