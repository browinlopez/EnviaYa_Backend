<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Poner a una persona en un area.
 */
class AsignarAreaRequest extends FormRequest
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
            'area_id' => 'sometimes|nullable|integer|exists:areas,id',
            'access_level' => 'sometimes|in:gestor,consulta',
        ];
    }
}
