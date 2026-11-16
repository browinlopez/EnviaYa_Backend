<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Models\Order\OrdersSales;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Mover un pedido de estado.
 */
class MoverPedidoRequest extends FormRequest
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
            'order_id' => 'required|integer',
            'state' => 'required|integer|in:'.implode(',', OrdersSales::DESTINOS_AL_MOVER),
            'user_id' => 'nullable|integer|exists:user,user_id',
        ];
    }
}
