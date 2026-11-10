<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Un producto nuevo del catalogo compartido.
 */
class CrearProductoDelCatalogoRequest extends FormRequest
{
    /*
     * El permiso lo resolvio el middleware de la ruta: `admin` y, donde
     * aplica, `modulo:<area>,gestionar`. Repetirlo aqui seria una segunda
     * fuente de verdad sobre quien puede que.
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
