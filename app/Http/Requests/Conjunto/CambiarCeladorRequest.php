<?php

declare(strict_types=1);

namespace App\Http\Requests\Conjunto;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Corregir un celador, o darlo de baja con `state`.
 */
class CambiarCeladorRequest extends FormRequest
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
            'state' => 'sometimes|boolean',
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|nullable|string|max:20',
            // Restablecer la clave: queda provisional hasta que él ponga la suya.
            'password' => ['sometimes', Password::min(8)],
        ];
    }
}
