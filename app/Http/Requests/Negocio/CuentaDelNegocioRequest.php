<?php

declare(strict_types=1);

namespace App\Http\Requests\Negocio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * «Cuánto te queda»: de qué periodo.
 *
 * Validaba en línea dentro del controlador; el acuerdo del proyecto es que
 * cada endpoint nuevo estrene su FormRequest (ver
 * `scripts/validaciones_en_linea.py`). Las reglas son las mismas.
 */
class CuentaDelNegocioRequest extends FormRequest
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
            'periodo' => 'nullable|in:mes,semana',
            'fecha' => 'nullable|date',
            // O un rango cualquiera, el del calendario del panel.
            'desde' => 'nullable|date|required_with:hasta',
            'hasta' => 'nullable|date|required_with:desde|after_or_equal:desde',
        ];
    }
}
