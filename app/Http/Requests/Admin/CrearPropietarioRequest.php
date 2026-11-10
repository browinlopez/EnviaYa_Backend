<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Crear un propietario de negocio.
 */
class CrearPropietarioRequest extends FormRequest
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
