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
     * El permiso lo resolvio el middleware de la ruta: `admin` y, donde
     * aplica, `modulo:<area>,gestionar`. Repetirlo aqui seria una segunda
     * fuente de verdad sobre quien puede que.
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
