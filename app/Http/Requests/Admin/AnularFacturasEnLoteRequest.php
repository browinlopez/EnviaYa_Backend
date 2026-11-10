<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Anular varias facturas de una vez.
 */
class AnularFacturasEnLoteRequest extends FormRequest
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
            'ids' => 'required|array|min:1|max:50',
            'ids.*' => 'integer',
            'motivo' => 'required|string|min:5|max:255',
        ];
    }
}
