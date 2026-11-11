<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Un producto nuevo del catalogo compartido.
 */
class CrearProductoDelCatalogoRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'category_id' => 'nullable|integer|exists:category,category_id',
            'busines_id' => 'nullable|integer|exists:business,busines_id',
            'price' => 'nullable|numeric|min:0',
            'amount' => 'nullable|integer|min:0',
        ];
    }
}
