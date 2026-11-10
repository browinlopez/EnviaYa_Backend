<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Dar de alta un domiciliario, por uno de dos caminos.
 *
 * `required_without:user_id`: o se ata a una cuenta que ya existe, o se crea
 * con sus datos. Sin ninguno de los dos no hay a quien dar de alta.
 */
class CrearDomiciliarioInternoRequest extends FormRequest
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
            'user_id' => 'nullable|integer|exists:user,user_id',
            'name' => 'required_without:user_id|string|max:255',
            'email' => 'required_without:user_id|email|max:255|unique:user,email',
            'password' => 'required_without:user_id|string|min:6',
            'phone' => 'nullable|string|max:20',
            'document' => 'nullable|string|max:50',
            'available' => 'sometimes|boolean',
        ];
    }
}
