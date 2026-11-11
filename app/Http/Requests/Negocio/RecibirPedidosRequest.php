<?php

declare(strict_types=1);

namespace App\Http\Requests\Negocio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El interruptor de recibir pedidos.
 */
class RecibirPedidosRequest extends FormRequest
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
            'accepting_orders' => 'required|boolean',
            /*
             * HASTA CUÁNDO DURA LA PAUSA. Opcional: «se fue la luz» no tiene
             * hora de vuelta y entonces la enciende él.
             *
             * El tope de un día no es capricho. Una pausa de tres semanas no
             * es una pausa: es una tienda que cerró, y eso lo decide el equipo
             * con `state`, no un interruptor del mostrador. Sin tope, la
             * manera de desaparecer del catálogo sin que nadie se entere sería
             * pausar hasta el año que viene.
             */
            'accepting_until' => 'nullable|date|after:now|before:'.now()->addDay()->addMinute()->toIso8601String(),
        ];
    }
}
