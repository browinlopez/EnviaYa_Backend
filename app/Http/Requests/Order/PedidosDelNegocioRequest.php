<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Los pedidos de una tienda.
 */
class PedidosDelNegocioRequest extends FormRequest
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
            'business_id' => 'required|integer',
        ];
    }
}
