<?php

declare(strict_types=1);

namespace App\Http\Requests\Negocio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Otra presentación de un producto que solo vende este negocio: su etiqueta,
 * su precio y, si el producto aún no tenía grupo, la etiqueta del original.
 */
class CrearVarianteRequest extends FormRequest
{
    /*
     * El permiso lo resuelve el middleware de la ruta, que es donde vive en
     * este backend. Repetirlo aqui seria una segunda fuente de verdad.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variante' => 'required|string|max:40',
            'price' => 'required|numeric|min:0',
            'amount' => 'nullable|numeric|min:0',
            'variante_actual' => 'nullable|string|max:40',
        ];
    }
}
