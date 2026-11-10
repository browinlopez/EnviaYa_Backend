<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Anular una factura.
 */
class AnularFacturaRequest extends FormRequest
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
            // El motivo es OBLIGATORIO. Una anulación sin explicación es un
            // agujero en la contabilidad que nadie puede reconstruir después.
            'motivo' => 'required|string|min:5|max:255',
        ];
    }
}
