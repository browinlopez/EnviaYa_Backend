<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lo que mas vende un negocio.
 *
 * `limit` tiene tope: sin el, un `limit=100000` recorre el catalogo entero
 * para pintar una lista que nadie lee.
 */
class MasVendidosDelNegocioRequest extends FormRequest
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
            'limit' => 'nullable|integer|min:1|max:50', // opcional para top N
        ];
    }
}
