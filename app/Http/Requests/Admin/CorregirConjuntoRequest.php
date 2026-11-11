<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Corregir un conjunto. Todo `sometimes`: el panel manda solo lo que cambio.
 */
class CorregirConjuntoRequest extends FormRequest
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
            'name' => 'sometimes|string|max:255',
            'address' => 'sometimes|nullable|string|max:255',
            'people_count' => 'sometimes|nullable|integer|min:0',
            'state' => 'sometimes|boolean',
            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',
            'municipality_id' => 'sometimes|nullable|integer|exists:municipalities,id',
            'towers_count' => 'sometimes|nullable|integer|min:1|max:500',
            'apartments_per_tower' => 'sometimes|nullable|integer|min:1|max:2000',

            'photo' => 'sometimes|nullable|string|max:2048',
            'phone' => 'sometimes|nullable|string|max:40',
            'email' => 'sometimes|nullable|email|max:120',
            'admin_name' => 'sometimes|nullable|string|max:120',
            'nit' => 'sometimes|nullable|string|max:40',
            'gate_notes' => 'sometimes|nullable|string',
            'require_authorization' => 'sometimes|boolean',
        ];
    }
}
