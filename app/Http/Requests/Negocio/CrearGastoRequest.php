<?php

declare(strict_types=1);

namespace App\Http\Requests\Negocio;

use App\Models\Negocio\GastoDelNegocio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Un gasto nuevo del negocio: arriendo, luz, nómina…
 *
 * Validaba en línea dentro del controlador; el acuerdo del proyecto es que
 * cada endpoint nuevo estrene su FormRequest (ver
 * `scripts/validaciones_en_linea.py`). Las reglas son las mismas.
 */
class CrearGastoRequest extends FormRequest
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
            'tipo' => ['required', Rule::in(array_keys(GastoDelNegocio::TIPOS))],
            'nombre' => 'nullable|string|max:60',
            'monto' => 'required|numeric|min:1|max:100000000',
            'frecuencia' => ['required', Rule::in([GastoDelNegocio::MENSUAL, GastoDelNegocio::UNA_VEZ])],
            'fecha' => 'nullable|date',
        ];
    }
}
