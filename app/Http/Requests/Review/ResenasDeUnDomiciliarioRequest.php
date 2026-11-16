<?php

declare(strict_types=1);

namespace App\Http\Requests\Review;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Las resenas de un domiciliario.
 */
class ResenasDeUnDomiciliarioRequest extends FormRequest
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
            'domiciliary_id' => 'required|integer|exists:domiciliary,domiciliary_id',
        ];
    }
}
