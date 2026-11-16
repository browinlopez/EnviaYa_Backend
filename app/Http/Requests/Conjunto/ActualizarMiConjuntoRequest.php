<?php

declare(strict_types=1);

namespace App\Http\Requests\Conjunto;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La ficha del conjunto, editada por su administrador.
 */
class ActualizarMiConjuntoRequest extends FormRequest
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
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:120',
            'admin_name' => 'nullable|string|max:120',
            'nit' => 'nullable|string|max:40',
            'gate_notes' => 'nullable|string|max:2000',
            'require_authorization' => 'nullable|boolean',
        ];
    }
}
