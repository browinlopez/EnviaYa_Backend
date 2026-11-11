<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A cuanta gente llegaria un push antes de mandarlo.
 */
class VistaPreviaDePushRequest extends FormRequest
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
            'segment' => 'sometimes|nullable|array',
            'segment.roles' => 'sometimes|nullable|array',
            'segment.municipalities' => 'sometimes|nullable|array',
            'segment.complexes' => 'sometimes|nullable|array',
            'segment.only_with_orders' => 'sometimes|boolean',
        ];
    }
}
