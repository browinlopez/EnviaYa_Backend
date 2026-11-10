<?php

namespace App\Http\Requests\Negocio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Buscar un cliente por telefono para afiliarlo.
 *
 * Minimo siete cifras: con menos, la busqueda devuelve a media ciudad y
 * esta pantalla sirve para encontrar a UNA persona.
 */
class BuscarClienteRequest extends FormRequest
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
            'telefono' => 'required|string|min:7|max:40',
        ];
    }
}
