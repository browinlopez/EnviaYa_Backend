<?php

declare(strict_types=1);

namespace App\Http\Requests\Conjunto;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La lista de turnos del conjunto, para el administrador: entre dos días y,
 * si se quiere, de un solo celador.
 */
class TurnosDelConjuntoRequest extends FormRequest
{
    /*
     * El permiso lo resuelve el middleware de la ruta, que es donde vive en
     * este backend. Repetirlo aqui seria una segunda fuente de verdad.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'user_id' => ['nullable', 'integer'],
        ];
    }
}
