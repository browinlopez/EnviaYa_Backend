<?php

declare(strict_types=1);

namespace App\Http\Requests\Negocio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Proponer un producto que no esta en el catalogo.
 *
 * Lo escribe el tendero y lo revisa el equipo: por eso `category_id` se
 * comprueba contra la tabla, para que no entre una categoria inventada.
 */
class ProponerProductoRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'barcode' => 'nullable|string|max:20',
            'brand' => 'nullable|string|max:120',
            'description' => 'nullable|string',
            'category_id' => 'required|integer|exists:category,category_id',
            'image' => 'nullable|string|max:2048',
        ];
    }
}
