<?php

declare(strict_types=1);

namespace App\Http\Requests\Domiciliary;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Corregir la ficha de un domiciliario.
 */
class ActualizarDomiciliarioRequest extends FormRequest
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
            'user_id' => 'required|integer|exists:user,user_id',
            // Campos del domiciliario
            'available' => 'boolean',
            'state' => 'boolean',
            // Campos del usuario
            'name' => 'string|max:255',
            // El correo es la credencial de acceso: no puede chocar con otro.
            'email' => 'email|max:255|unique:user,email,'.$this->input('user_id').',user_id',
            'phone' => 'string|max:20',
            'address' => 'string|max:255',
        ];
    }
}
