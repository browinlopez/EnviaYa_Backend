<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A cuanta gente llegaria un push antes de mandarlo.
 */
class VistaPreviaDePushRequest extends FormRequest
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
            'segment' => 'sometimes|nullable|array',
            'segment.roles' => 'sometimes|nullable|array',
            'segment.municipalities' => 'sometimes|nullable|array',
            'segment.complexes' => 'sometimes|nullable|array',
            'segment.only_with_orders' => 'sometimes|boolean',
        ];
    }
}
