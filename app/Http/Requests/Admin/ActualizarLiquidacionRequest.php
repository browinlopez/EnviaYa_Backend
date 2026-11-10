<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mover el estado de una liquidacion, o anotarle la referencia de pago.
 */
class ActualizarLiquidacionRequest extends FormRequest
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
            'state' => 'sometimes|integer|in:0,1,2,3',
            'payment_reference' => 'sometimes|nullable|string|max:100',
            'notes' => 'sometimes|nullable|string|max:500',
        ];
    }
}
