<?php

namespace App\Http\Requests\Negocio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Que se vende mas, en una ventana de dias.
 */
class MasVendidosRequest extends FormRequest
{
    /*
     * El permiso ya lo resolvio el middleware `negocio`: puso en la peticion
     * el negocio de la sesion y piso lo que mandara el cliente. Repetirlo aca
     * seria una segunda fuente de verdad sobre quien puede que.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dias' => 'sometimes|integer|in:7,30,90,365',
            'limite' => 'sometimes|integer|min:1|max:50',
        ];
    }
}
