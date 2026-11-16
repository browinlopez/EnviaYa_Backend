<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Una categoria nueva, de producto o de negocio.
 *
 * `scope` decide sobre que tabla se escribe, asi que es obligatorio: sin el
 * no hay forma de saber cual de las dos categorias se esta creando.
 */
class CrearCategoriaRequest extends FormRequest
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
            'scope' => 'required|in:product,business',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'state' => 'sometimes|boolean',
            'business_category_ids' => 'sometimes|array',
            'business_category_ids.*' => 'integer|exists:category_business,id',
        ];
    }
}
