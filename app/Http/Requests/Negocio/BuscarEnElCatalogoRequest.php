<?php

declare(strict_types=1);

namespace App\Http\Requests\Negocio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Buscar en el catalogo compartido de VeciPa'Ya.
 *
 * `limit` tiene tope: sin el, un `limit=100000` recorre el catalogo entero
 * para pintar una lista que nadie lee.
 */
class BuscarEnElCatalogoRequest extends FormRequest
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
            'q' => 'nullable|string|max:120',
            'limit' => 'nullable|integer|min:1|max:50',
        ];
    }
}
