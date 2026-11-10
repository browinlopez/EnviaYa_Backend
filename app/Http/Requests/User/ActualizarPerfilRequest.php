<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El perfil de quien tiene la sesion abierta.
 *
 * Todo opcional: la app manda solo lo que cambio. Lo que no se puede dejar al
 * azar es el correo, porque es por donde se recupera la cuenta.
 */
class ActualizarPerfilRequest extends FormRequest
{
    /*
     * La ruta ya exige sesion. Y el usuario NO viene en el cuerpo: sale de
     * ella, asi que aqui no hay a quien suplantar.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /*
         * EL `unique` SE IGNORA A SI MISMO.
         *
         * Sin el tercer argumento, guardar el perfil sin tocar el correo
         * —que es lo que hacen las versiones publicadas de la app— choca
         * contra la propia fila y responde «ese correo ya lo usa otra
         * cuenta», que es falso y deja al comprador sin poder guardar su
         * telefono.
         *
         * El id sale de la SESION (`$this->user()`), nunca del cuerpo: si
         * viniera de fuera, cualquiera podria excluir la fila de otro y
         * quedarse con su correo.
         */
        $yo = $this->user()?->user_id;

        return [
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|unique:user,email,'.$yo.',user_id',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:225',
            'current_password' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Ese correo ya lo usa otra cuenta.',
        ];
    }
}
