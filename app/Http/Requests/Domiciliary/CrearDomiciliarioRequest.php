<?php

declare(strict_types=1);

namespace App\Http\Requests\Domiciliary;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Dar de alta un domiciliario.
 */
class CrearDomiciliarioRequest extends FormRequest
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
            'email' => 'required|string|email|unique:user,email',
            'password' => 'required|string|min:6',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:225',
            'available' => 'boolean',
            'qualification' => 'numeric|min:0|max:5',
            'state' => 'boolean',
        ];
    }
}
