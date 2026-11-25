<?php

declare(strict_types=1);

namespace App\Http\Requests\Negocio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Las ventas del mostrador de un día.
 *
 * Validaba en línea dentro del controlador; el acuerdo del proyecto es que
 * cada endpoint nuevo estrene su FormRequest (ver
 * `scripts/validaciones_en_linea.py`). Las reglas son las mismas.
 */
class VentasDelDiaRequest extends FormRequest
{
    /** La puerta la cuida la ruta (sesión y pertenencia al negocio). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fecha' => 'nullable|date',
        ];
    }
}
