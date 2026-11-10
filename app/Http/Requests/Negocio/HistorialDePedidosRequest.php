<?php

namespace App\Http\Requests\Negocio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El archivo de pedidos: rango, estado y forma de pago.
 *
 * Todo opcional: sin fechas, el controlador usa la semana en curso, que es
 * la pregunta que el tendero hace casi siempre.
 */
class HistorialDePedidosRequest extends FormRequest
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
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date',
            'estado' => 'nullable|in:todos,entregados,cancelados',
            'pago' => 'nullable|in:todos,efectivo,app,fiado',
            'pagina' => 'nullable|integer|min:1',
        ];
    }
}
