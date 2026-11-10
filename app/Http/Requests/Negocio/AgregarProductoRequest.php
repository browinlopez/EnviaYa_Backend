<?php

namespace App\Http\Requests\Negocio;

use App\Support\UnidadDeVenta;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Poner un producto del catalogo a la venta en esta tienda.
 */
class AgregarProductoRequest extends FormRequest
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
            'products_id' => 'required|integer|exists:products,products_id',
            'price' => 'required|numeric|min:0',
            'amount' => 'required|integer|min:0',
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
        ];
    }
}
