<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Una nota en el hilo de una PQRS. `is_internal` la deja fuera de lo que ve
 * quien la radico.
 */
class AnotarEnPqrsRequest extends FormRequest
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
            'note' => 'required|string',
            'is_internal' => 'sometimes|boolean',
        ];
    }
}
