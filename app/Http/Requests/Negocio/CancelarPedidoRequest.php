<?php

declare(strict_types=1);

namespace App\Http\Requests\Negocio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El motivo por el que la tienda cancela un pedido.
 *
 * NO es burocracia: es lo unico que el cliente va a leer. De ahi el minimo de
 * tres letras, que descarta el «ya» de quien tiene prisa.
 */
class CancelarPedidoRequest extends FormRequest
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
            'motivo' => 'required|string|min:3|max:500',
        ];
    }
}
