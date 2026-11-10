<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Borrar una resena.
 *
 * `scope` decide sobre que tabla se borra: un valor libre aqui no es un campo
 * mal puesto, es un borrado sin destino.
 */
class BorrarResenaRequest extends FormRequest
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
            'scope' => 'required|in:business,domiciliary,user',
            'reviews_id' => 'required|integer',
        ];
    }
}
