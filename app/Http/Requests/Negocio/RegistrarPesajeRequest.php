<?php

declare(strict_types=1);

namespace App\Http\Requests\Negocio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lo que peso de verdad cada linea del pedido.
 */
class RegistrarPesajeRequest extends FormRequest
{
    /*
     * El permiso ya lo resolvio el middleware `negocio`: puso en la peticion
     * el negocio de la sesion y piso lo que mandara el cliente. Repetirlo aca
     * seria una segunda fuente de verdad sobre quien puede que.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id' => 'required|integer',
            'lineas' => 'required|array|min:1',
            'lineas.*.orderDet_id' => 'required|integer',
            /*
             * Hasta tres decimales: la bascula de mostrador pesa en gramos y
             * un kilo tiene mil. Mas precision seria inventarla.
             */
            'lineas.*.qty_final' => 'required|numeric|min:0.001|max:5000',
        ];
    }
}
