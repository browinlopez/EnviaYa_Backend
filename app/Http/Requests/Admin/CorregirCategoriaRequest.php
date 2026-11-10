<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Corregir una categoria existente.
 */
class CorregirCategoriaRequest extends FormRequest
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
            'scope' => 'required|in:product,business',
            'id' => 'required|integer',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'state' => 'sometimes|boolean',
            'business_category_ids' => 'sometimes|array',
            'business_category_ids.*' => 'integer|exists:category_business,id',
        ];
    }
}
