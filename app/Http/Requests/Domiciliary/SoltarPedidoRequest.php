<?php

declare(strict_types=1);

namespace App\Http\Requests\Domiciliary;

use App\Services\NovedadesDeEntrega;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Soltar un pedido en camino, con su motivo.
 *
 * Validaba en línea dentro del controlador; el acuerdo del proyecto es que
 * cada endpoint nuevo estrene su FormRequest (ver
 * `scripts/validaciones_en_linea.py`). Las reglas son las mismas.
 */
class SoltarPedidoRequest extends FormRequest
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
            'motivo' => ['required', 'string', Rule::in(array_keys(NovedadesDeEntrega::MOTIVOS_PARA_SOLTAR))],
            'nota' => 'nullable|string|max:500',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'motivo.in' => 'Ese motivo no existe.',
        ];
    }
}
