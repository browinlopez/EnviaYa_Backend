<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Donde va el domiciliario con un pedido encima.
 *
 * `exists` y no solo `integer`: una posicion atada a un pedido que no existe
 * es una fila que nadie va a poder leer nunca.
 */
class GuardarPosicionRequest extends FormRequest
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
            'domiciliary_id' => 'required|exists:domiciliary,domiciliary_id',
            'orderSales_id' => 'required|exists:orderssales,orderSales_id',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'state' => 'nullable|integer',
        ];
    }
}
