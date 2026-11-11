<?php

declare(strict_types=1);

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
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'integer',
        ];
    }
}
