<?php

declare(strict_types=1);

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
            /*
             * CUANTOS POR PAGINA LO PIDE QUIEN MIRA, no el servidor.
             *
             * En una pantalla de ordenador caben veinticinco de un vistazo;
             * en un telefono no, y ademas la lista se trae sola segun se
             * baja. Pedir veinticinco ahi es traer el doble de lo que se va
             * a leer antes de que alguien decida si sigue.
             *
             * Con tope: sin el, `por_pagina=100000` convierte la pagina en
             * la tabla entera y el tope de 92 dias deja de servir de nada.
             */
            'por_pagina' => 'nullable|integer|min:5|max:50',
        ];
    }
}
