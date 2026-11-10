<?php

namespace App\Http\Requests\Negocio;

use App\Support\UnidadDeVenta;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Precio, existencias y disponibilidad de un producto.
 */
class ActualizarProductoRequest extends FormRequest
{
    /*
     * El permiso lo resuelve el middleware de la ruta, que es donde vive en
     * este backend. Repetirlo aqui seria una segunda fuente de verdad sobre
     * quien puede que, y la que se olvide de actualizar sera la que mande.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'price' => 'nullable|numeric|min:0',
            'amount' => 'nullable|integer|min:0',
            /*
             * COMO LO VENDE ESTA TIENDA. Por unidad o al peso --kilo, libra o
             * gramo-- y es del par tienda-producto: la misma referencia va
             * empacada aca y al peso en la de enfrente.
             *
             * Con una unidad de peso, `price` pasa a significar «precio por
             * esa unidad» y las existencias viven en `stock_weight`, que
             * admite decimales. `amount` se queda para lo que se vende por
             * unidad: migrarlo a decimal habria cambiado en silencio el
             * significado de veintidos sitios.
             */
            'sale_unit' => 'nullable|string|in:'.implode(',', UnidadDeVenta::TODAS),
            'stock_weight' => 'nullable|numeric|min:0|max:100000',
            'min_qty' => 'nullable|numeric|min:0.001|max:100',
            'step_qty' => 'nullable|numeric|min:0.001|max:100',
            'expiration_date' => 'nullable|date',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'nullable|integer|exists:category,category_id',
            'state' => 'nullable|boolean',
        ];
    }
}
