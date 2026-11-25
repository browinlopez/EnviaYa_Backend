<?php

declare(strict_types=1);

namespace App\Http\Requests\Negocio;

use App\Support\UnidadDeVenta;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «Hormiga culona, $3.000»: un producto que el catálogo no tiene, solo para este negocio.
 *
 * Validaba en línea dentro del controlador; el acuerdo del proyecto es que
 * cada endpoint nuevo estrene su FormRequest (ver
 * `scripts/validaciones_en_linea.py`). Las reglas son las mismas.
 */
class CrearProductoPropioRequest extends FormRequest
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
            'name' => 'required|string|min:2|max:120',
            'price' => 'required|numeric|min:1|max:100000000',
            'sale_unit' => 'nullable|string|in:'.implode(',', UnidadDeVenta::TODAS),
            'por_valor' => 'nullable|boolean',
            'amount' => 'nullable|numeric|min:0|max:100000',
            'costo' => 'nullable|numeric|min:0|max:100000000',
        ];
    }
}
