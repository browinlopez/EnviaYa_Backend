<?php

namespace App\Http\Requests\Marketing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Comprobar un cupon contra un subtotal antes de aplicarlo.
 */
class ValidarCuponRequest extends FormRequest
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
            'code' => 'required|string|max:40',
            'subtotal' => 'required|numeric|min:0',
            'business_id' => 'sometimes|nullable|integer',
        ];
    }
}
