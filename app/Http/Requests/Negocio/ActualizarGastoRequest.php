<?php

declare(strict_types=1);

namespace App\Http\Requests\Negocio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cambiar un gasto del negocio.
 *
 * Validaba en línea dentro del controlador; el acuerdo del proyecto es que
 * cada endpoint nuevo estrene su FormRequest (ver
 * `scripts/validaciones_en_linea.py`). Las reglas son las mismas.
 */
class ActualizarGastoRequest extends FormRequest
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
            'monto' => 'nullable|numeric|min:1|max:100000000',
            'nombre' => 'nullable|string|max:60',
            'solo_este_mes' => 'nullable|boolean',
        ];
    }
}
