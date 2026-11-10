<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Borrar resenas en lote.
 *
 * Tope de cien, y no «las que sean»: un lote sin tope es un borrado masivo a
 * un descuido de distancia, y borrar resenas no se deshace.
 */
class BorrarResenasEnLoteRequest extends FormRequest
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
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'integer',
        ];
    }
}
