<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Un negocio concreto. `user_id` es opcional: si viene, la respuesta marca
 * cuales son sus favoritos.
 */
class VerNegocioRequest extends FormRequest
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
            'busines_id' => 'required|integer|exists:business,busines_id',
            'user_id' => 'sometimes|nullable|integer|exists:user,user_id',
        ];
    }
}
