<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Crear un propietario de negocio.
 */
class CrearPropietarioRequest extends FormRequest
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
            'email' => 'required|email|max:255|unique:user,email',
            'password' => 'required|string|min:6',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'document_type_id' => 'nullable|integer|exists:document_types,id',
            'document_number' => 'nullable|string|max:50',
            'birthdate' => 'nullable|date',
            // La columna es varchar(45): validar con un tope mayor solo
            // cambiaría el 422 por un error de truncado en la base.
            'contact_secondary' => 'nullable|string|max:45',
            'notes' => 'nullable|string',
            'state' => 'sometimes|boolean',
            'business_ids' => 'sometimes|array',
            'business_ids.*' => 'integer|exists:business,busines_id',
        ];
    }
}
