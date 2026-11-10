<?php

namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Las categorias de producto de un tipo de negocio.
 *
 * `type` no es un filtro opcional: las categorias cuelgan del tipo, asi que
 * sin el la lista no significa nada.
 */
class CategoriasDeUnTipoRequest extends FormRequest
{
    /*
     * El permiso lo resuelve el middleware de la ruta, que es donde vive en
     * este backend. Repetirlo aqui seria una segunda fuente de verdad sobre
     * quien puede que, y la que se olvide de actualizar sera la que mande.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => 'required|integer|exists:category_business,id',
        ];
    }
}
