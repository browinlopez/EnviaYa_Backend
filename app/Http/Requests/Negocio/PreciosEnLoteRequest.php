<?php

declare(strict_types=1);

namespace App\Http\Requests\Negocio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cambiar varios precios de una vez.
 */
class PreciosEnLoteRequest extends FormRequest
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
            'cambios' => 'required|array|min:1|max:500',
            'cambios.*.products_id' => 'required|integer',
            'cambios.*.price' => 'nullable|numeric|min:0',
            'cambios.*.amount' => 'nullable|integer|min:0',
        ];
    }
}
