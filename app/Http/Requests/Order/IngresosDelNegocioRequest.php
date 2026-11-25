<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lo que ha vendido una tienda.
 */
class IngresosDelNegocioRequest extends FormRequest
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
            'business_id' => 'required|integer|exists:business,busines_id',
            /*
             * UN RANGO PROPIO, ADEMAS DE DIA, SEMANA Y MES.
             *
             * Los tres periodos de siempre los calcula el servidor y no se
             * tocan. Esto es lo que el tendero elige con el calendario para
             * una pregunta concreta: «¿cuanto hice del 3 al 9?».
             *
             * Van juntas o no van: media pareja no define un rango, y
             * rellenar la que falta con una fecha inventada daria una cifra
             * que parece un dato.
             */
            'desde' => 'nullable|date|required_with:hasta',
            'hasta' => 'nullable|date|required_with:desde',
        ];
    }
}
