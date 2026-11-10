<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mover el estado de una liquidacion, o anotarle la referencia de pago.
 */
class ActualizarLiquidacionRequest extends FormRequest
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
            'state' => 'sometimes|integer|in:0,1,2,3',
            'payment_reference' => 'sometimes|nullable|string|max:100',
            'notes' => 'sometimes|nullable|string|max:500',
        ];
    }
}
