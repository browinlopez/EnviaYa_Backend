<?php

declare(strict_types=1);

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Corregir un negocio.
 */
class ActualizarNegocioRequest extends FormRequest
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
            'name' => 'nullable|string',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'municipality_id' => 'nullable|integer|exists:municipalities,id',
            'NIT' => 'nullable|string',
            'razonSocial_DCD' => 'nullable|string',
            'logo' => 'nullable|string',
            'type' => 'nullable|integer|in:1,2',
            'state' => 'nullable|boolean',
            'owner_ids' => 'nullable|array',
            'owner_ids.*' => 'integer|exists:owner,owner_id',
        ];
    }
}
