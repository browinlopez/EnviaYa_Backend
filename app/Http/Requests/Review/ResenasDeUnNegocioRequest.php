<?php

namespace App\Http\Requests\Review;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Las resenas de un negocio.
 */
class ResenasDeUnNegocioRequest extends FormRequest
{
    /*
     * Ruta PUBLICA: no hay sesion que autorizar todavia. Lo que la protege es
     * el limitador de peticiones y que el token sea bueno, no un permiso.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'business_id' => 'required|integer|exists:business,busines_id',
        ];
    }
}
